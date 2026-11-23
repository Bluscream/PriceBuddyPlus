<?php

declare(strict_types=1);

namespace App\Services;

use App\Dto\MarketplaceListingDto;
use App\Dto\SearchWatchCheckResultDto;
use App\Dto\SearchWatchEventDto;
use App\Enums\Marketplace;
use App\Enums\SearchWatchEventKind;
use App\Exceptions\MarketplaceBlockedException;
use App\Exceptions\MarketplaceException;
use App\Models\SearchWatch;
use App\Models\SearchWatchListing;
use App\Notifications\SearchWatchNotification;
use App\Services\Marketplaces\EbaySearch;
use App\Services\Marketplaces\KleinanzeigenSearch;
use App\Services\Marketplaces\MarketplaceSearch;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * Checks one search watch: fetches its search page, keeps the listings it has seen, and
 * notifies the owner about new matching listings and price drops.
 */
final class SearchWatchRunner
{
    /** First backoff after a block; doubles per consecutive block up to MAX_BACKOFF_MINUTES. */
    public const int BASE_BACKOFF_MINUTES = 30;

    public const int MAX_BACKOFF_MINUTES = 24 * 60;

    /** The results page shows a teaser; this only guards against a malformed page. */
    private const int MAX_DESCRIPTION_CHARS = 2000;

    /**
     * @param  array<string, MarketplaceSearch>  $clients  keyed by Marketplace value
     */
    public function __construct(private readonly array $clients) {}

    public static function new(): self
    {
        return new self([
            Marketplace::Kleinanzeigen->value => new KleinanzeigenSearch,
            Marketplace::Ebay->value => EbaySearch::fromConfig(),
        ]);
    }

    public function check(SearchWatch $watch, ?CarbonInterface $now = null): SearchWatchCheckResultDto
    {
        $now ??= now();
        $client = $this->client($watch);

        try {
            $found = $client->search($watch->search_url);
        } catch (MarketplaceBlockedException $e) {
            return $this->recordBlock($watch, $e, $now);
        } catch (MarketplaceException $e) {
            return $this->recordFailure($watch, $e, $now);
        }

        $baseline = ! $watch->listings()->exists();
        $events = [];
        foreach ($found as $dto) {
            $event = $this->upsert($watch, $dto, $now, $baseline);
            if ($event !== null) {
                $events[] = $event;
            }
        }

        $watch->forceFill([
            'last_checked_at' => $now,
            'last_succeeded_at' => $now,
            'blocked_until' => null,
            'consecutive_failures' => 0,
            'last_error' => null,
        ])->save();

        if ($events !== []) {
            $watch->user->notify(new SearchWatchNotification($watch, $events));
        }

        return new SearchWatchCheckResultDto(ok: true, listingsSeen: \count($found), events: $events, baseline: $baseline);
    }

    /**
     * Fetch the search and score each listing against the watch's filters, without storing
     * anything or notifying anyone. The watch may be unsaved, so a form can be tried out.
     *
     * @return list<array{listing: MarketplaceListingDto, mismatch: ?string}>
     *
     * @throws MarketplaceException
     */
    public function preview(SearchWatch $watch): array
    {
        return \array_map(
            fn (MarketplaceListingDto $dto): array => ['listing' => $dto, 'mismatch' => $this->mismatch($watch, $dto)],
            $this->client($watch)->search($watch->search_url),
        );
    }

    /**
     * Score the stored listings against the watch's current filters, so a filter change shows
     * up straight away instead of at the next check. Never notifies.
     *
     * @return int how many listings changed
     */
    public function rescore(SearchWatch $watch): int
    {
        $changed = 0;
        foreach ($watch->listings()->get() as $listing) {
            $matches = $this->matches($watch, $listing->toDto());
            if ($matches !== $listing->matches_filters) {
                $listing->forceFill(['matches_filters' => $matches])->save();
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * Whether a listing passes the watch's own filters, on top of whatever the search URL filters.
     */
    public function matches(SearchWatch $watch, MarketplaceListingDto $dto): bool
    {
        return $this->mismatch($watch, $dto) === null;
    }

    /**
     * Why a listing fails the watch's filters, as a short sentence for the user, or null when it
     * passes them.
     */
    public function mismatch(SearchWatch $watch, MarketplaceListingDto $dto): ?string
    {
        if ($dto->price === null && $watch->require_price) {
            return self::reason('No price given.');
        }

        if ($dto->price !== null && $watch->min_price !== null && $dto->price < $watch->min_price) {
            return self::reason('Below the minimum price.');
        }

        if ($dto->price !== null && $watch->max_price !== null && $dto->price > $watch->max_price) {
            return self::reason('Above the maximum price.');
        }

        $maxDistance = $watch->effectiveMaxDistanceKm();
        if ($maxDistance !== null && $dto->distanceKm !== null && $dto->distanceKm > $maxDistance) {
            return self::reason('Further than :km km away.', ['km' => $maxDistance]);
        }

        $title = \mb_strtolower($dto->title);
        $include = self::keywords($watch->include_keywords);
        if ($include !== [] && ! \array_any($include, static fn (string $k): bool => \str_contains($title, $k))) {
            return self::reason('The title has none of the required words.');
        }

        $text = $title.' '.\mb_strtolower((string) $dto->description);
        $excluded = \array_find(self::keywords($watch->exclude_keywords), static fn (string $k): bool => \str_contains($text, $k));

        return $excluded !== null ? self::reason('Mentions “:word”.', ['word' => $excluded]) : null;
    }

    private function upsert(SearchWatch $watch, MarketplaceListingDto $dto, CarbonInterface $now, bool $baseline): ?SearchWatchEventDto
    {
        $matches = $this->matches($watch, $dto);
        $attributes = [
            'title' => $dto->title,
            'description' => $dto->description !== null ? \mb_substr($dto->description, 0, self::MAX_DESCRIPTION_CHARS) : null,
            'url' => $dto->url,
            'image' => $dto->image,
            'location' => $dto->location !== null ? \mb_substr($dto->location, 0, 255) : null,
            'distance_km' => $dto->distanceKm,
            'price' => $dto->price,
            'price_text' => $dto->priceText,
            'negotiable' => $dto->negotiable,
            'matches_filters' => $matches,
            'last_seen_at' => $now,
        ];

        /** @var ?SearchWatchListing $listing */
        $listing = $watch->listings()->where('external_id', $dto->externalId)->first();
        if ($listing === null) {
            $listing = $watch->listings()->create($attributes + [
                'external_id' => $dto->externalId,
                'first_price' => $dto->price,
                'lowest_price' => $dto->price,
                'first_seen_at' => $now,
            ]);

            return ! $baseline && $matches && $watch->notify_new_listings
                ? new SearchWatchEventDto(SearchWatchEventKind::NewListing, $listing)
                : null;
        }

        $oldPrice = $listing->price;
        $listing->fill($attributes);
        if ($dto->price !== null && ($listing->lowest_price === null || $dto->price < $listing->lowest_price)) {
            $listing->lowest_price = $dto->price;
        }
        $listing->save();

        $dropped = $oldPrice !== null && $dto->price !== null && $dto->price < $oldPrice - 0.005;

        return $dropped && $matches && $watch->notify_price_drops
            ? new SearchWatchEventDto(SearchWatchEventKind::PriceDrop, $listing, $oldPrice)
            : null;
    }

    private function recordBlock(SearchWatch $watch, MarketplaceBlockedException $e, CarbonInterface $now): SearchWatchCheckResultDto
    {
        $failures = $watch->consecutive_failures + 1;
        $backoff = \min(self::MAX_BACKOFF_MINUTES, self::BASE_BACKOFF_MINUTES * (2 ** \min($failures - 1, 10)));
        $backoffSeconds = \max($backoff * 60, $e->retryAfterSeconds ?? 0);

        $watch->forceFill([
            'last_checked_at' => $now,
            'blocked_until' => $now->copy()->addSeconds($backoffSeconds),
            'consecutive_failures' => $failures,
            'last_error' => $e->getMessage(),
        ])->save();

        Log::warning('Search watch blocked, backing off', [
            'search_watch_id' => $watch->id,
            'marketplace' => $watch->marketplace->value,
            'backoff_seconds' => $backoffSeconds,
        ]);

        return new SearchWatchCheckResultDto(ok: false, blocked: true, error: $e->getMessage());
    }

    private function recordFailure(SearchWatch $watch, MarketplaceException $e, CarbonInterface $now): SearchWatchCheckResultDto
    {
        $watch->forceFill([
            'last_checked_at' => $now,
            'consecutive_failures' => $watch->consecutive_failures + 1,
            'last_error' => $e->getMessage(),
        ])->save();

        Log::error('Search watch check failed', [
            'search_watch_id' => $watch->id,
            'marketplace' => $watch->marketplace->value,
            'error' => $e->getMessage(),
        ]);

        return new SearchWatchCheckResultDto(ok: false, error: $e->getMessage());
    }

    /**
     * @param  array<string, int|string>  $replace
     */
    private static function reason(string $text, array $replace = []): string
    {
        $translated = __($text, $replace);

        return \is_string($translated) ? $translated : $text;
    }

    private function client(SearchWatch $watch): MarketplaceSearch
    {
        return $this->clients[$watch->marketplace->value]
            ?? throw new MarketplaceException(\sprintf('No client for marketplace %s.', $watch->marketplace->value));
    }

    /**
     * @param  ?array<mixed>  $keywords
     * @return list<string>
     */
    private static function keywords(?array $keywords): array
    {
        $clean = [];
        foreach ($keywords ?? [] as $keyword) {
            if (\is_string($keyword) && \trim($keyword) !== '') {
                $clean[] = \mb_strtolower(\trim($keyword));
            }
        }

        return $clean;
    }
}
