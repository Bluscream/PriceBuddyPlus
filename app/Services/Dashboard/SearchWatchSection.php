<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\SearchWatch;
use App\Models\SearchWatchListing;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Data for the dashboard's "Marketplace finds" section: how each search watch is doing, the
 * newest matching listings, and the listings priced furthest below what their search
 * usually asks.
 */
final readonly class SearchWatchSection
{
    public const int FINDS_LIMIT = 6;

    public const int DEALS_LIMIT = 6;

    /** Fewer priced matches than this make a median too noisy to call anything a deal. */
    public const int MIN_SAMPLES_FOR_MEDIAN = 4;

    public function __construct(private User $user) {}

    /**
     * @return array{
     *     watches: Collection<int, SearchWatch>,
     *     finds: Collection<int, SearchWatchListing>,
     *     deals: list<array{listing: SearchWatchListing, median: float, percentBelow: int}>,
     * }
     */
    public function data(): array
    {
        return [
            'watches' => $this->watches(),
            'finds' => $this->latestFinds(),
            'deals' => $this->bestDeals(),
        ];
    }

    /**
     * Every watch with its count of matching listings still on the marketplace, and how many
     * of those arrived since the owner last opened the watch.
     *
     * @return Collection<int, SearchWatch>
     */
    public function watches(): Collection
    {
        return SearchWatch::query()
            ->where('user_id', $this->user->id)
            ->withCount([
                'listings as matching_listings_count' => static fn (Builder $q) => SearchWatchListing::whereStillListed($q->where('matches_filters', true)),
                // search_watches here is the outer, counted watch.
                'listings as new_listings_count' => static fn (Builder $q) => SearchWatchListing::whereStillListed($q->where('matches_filters', true))
                    ->where(static fn (Builder $v) => $v->whereNull('search_watches.listings_viewed_at')
                        ->orWhereColumn('search_watch_listings.first_seen_at', '>', 'search_watches.listings_viewed_at')),
            ])
            // Watches with something to look at first; the empty ones trail behind.
            ->orderByDesc('enabled')
            ->orderByDesc('matching_listings_count')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, SearchWatchListing>
     */
    public function latestFinds(): Collection
    {
        return $this->activeMatches()
            ->orderByDesc('first_seen_at')
            ->limit(self::FINDS_LIMIT)
            ->get();
    }

    /**
     * Matching listings still on the marketplace, ranked by how far below the median price of
     * everything their watch has matched they are.
     *
     * @return list<array{listing: SearchWatchListing, median: float, percentBelow: int}>
     */
    public function bestDeals(): array
    {
        $medians = $this->medianPrices();
        $deals = [];
        foreach ($this->activeMatches()->whereNotNull('price')->get() as $listing) {
            $median = $medians[$listing->search_watch_id] ?? null;
            if ($median === null || $listing->price === null || $listing->price >= $median) {
                continue;
            }
            $deals[] = [
                'listing' => $listing,
                'median' => $median,
                'percentBelow' => (int) \round(($median - $listing->price) / $median * 100),
            ];
        }

        \usort($deals, static fn (array $a, array $b): int => $b['percentBelow'] <=> $a['percentBelow']);

        return \array_slice($deals, 0, self::DEALS_LIMIT);
    }

    /**
     * @return Builder<SearchWatchListing>
     */
    private function activeMatches(): Builder
    {
        return SearchWatchListing::query()
            ->with('searchWatch')
            ->where('matches_filters', true)
            ->stillListed()
            ->whereHas('searchWatch', fn (Builder $q) => $q->where('user_id', $this->user->id)->where('enabled', true));
    }

    /**
     * The median price of every priced listing each watch has matched, gone ones included,
     * since together they say what the search usually costs.
     *
     * @return array<int, float> keyed by search watch id
     */
    private function medianPrices(): array
    {
        $prices = SearchWatchListing::query()
            ->where('matches_filters', true)
            ->whereNotNull('price')
            ->whereHas('searchWatch', fn (Builder $q) => $q->where('user_id', $this->user->id))
            ->get(['search_watch_id', 'price'])
            ->groupBy('search_watch_id');

        $medians = [];
        foreach ($prices as $watchId => $listings) {
            if ($listings->count() >= self::MIN_SAMPLES_FOR_MEDIAN) {
                $median = $listings->median('price');
                if (\is_numeric($median)) {
                    $medians[(int) $watchId] = (float) $median;
                }
            }
        }

        return $medians;
    }
}
