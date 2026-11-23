<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Dto\MarketplaceListingDto;
use App\Enums\Marketplace;
use App\Enums\SearchWatchEventKind;
use App\Exceptions\MarketplaceBlockedException;
use App\Exceptions\MarketplaceException;
use App\Models\SearchWatch;
use App\Models\User;
use App\Notifications\SearchWatchNotification;
use App\Services\Marketplaces\MarketplaceSearch;
use App\Services\SearchWatchRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\SearchWatchAssertions;

final class SearchWatchRunnerTest extends TestCase
{
    use RefreshDatabase;
    use SearchWatchAssertions;

    private FakeMarketplace $marketplace;

    private SearchWatchRunner $runner;

    private SearchWatch $watch;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Carbon::setTestNow('2026-11-23 12:00:00');

        $this->marketplace = new FakeMarketplace;
        $this->runner = new SearchWatchRunner([Marketplace::Kleinanzeigen->value => $this->marketplace]);
        $this->watch = SearchWatch::factory()->for(User::factory())->create();
    }

    private static function listing(string $id, ?float $price, int $distanceKm = 10, ?string $title = null, ?string $description = null): MarketplaceListingDto
    {
        return new MarketplaceListingDto(
            externalId: $id,
            title: $title ?? 'Pinguino '.$id,
            url: 'https://www.kleinanzeigen.de/s-anzeige/x/'.$id.'-176-1',
            price: $price,
            priceText: $price !== null ? $price.' €' : null,
            distanceKm: $distanceKm,
            description: $description,
        );
    }

    public function test_the_first_check_saves_a_baseline_without_notifying(): void
    {
        $this->marketplace->queue([self::listing('1', 100.0), self::listing('2', 200.0)]);

        $result = $this->runner->check($this->watch);

        self::assertTrue($result->ok);
        self::assertTrue($result->baseline);
        self::assertSame([], $result->events);
        self::assertSame(2, $this->watch->listings()->count());
        Notification::assertNothingSent();
    }

    public function test_a_listing_added_after_the_baseline_is_notified(): void
    {
        $this->marketplace->queue([self::listing('1', 100.0)]);
        $this->runner->check($this->watch);

        $this->marketplace->queue([self::listing('2', 90.0), self::listing('1', 100.0)]);
        $result = $this->runner->check($this->watch->refresh());

        self::assertCount(1, $result->events);
        self::assertSame(SearchWatchEventKind::NewListing, $result->events[0]->kind);
        self::assertSame('2', $result->events[0]->listing->external_id);
        Notification::assertSentTo($this->watch->user, SearchWatchNotification::class);
    }

    public function test_a_lower_price_on_a_known_listing_is_a_price_drop(): void
    {
        $this->marketplace->queue([self::listing('1', 150.0)]);
        $this->runner->check($this->watch);

        $this->marketplace->queue([self::listing('1', 120.0)]);
        $result = $this->runner->check($this->watch->refresh());

        self::assertCount(1, $result->events);
        self::assertSame(SearchWatchEventKind::PriceDrop, $result->events[0]->kind);
        self::assertSame(150.0, $result->events[0]->oldPrice);
        $listing = $this->watch->listings()->sole();
        self::assertSame(120.0, $listing->price);
        self::assertSame(150.0, $listing->first_price);
        self::assertSame(120.0, $listing->lowest_price);
    }

    public function test_a_price_increase_is_recorded_but_not_notified(): void
    {
        $this->marketplace->queue([self::listing('1', 100.0)]);
        $this->runner->check($this->watch);

        $this->marketplace->queue([self::listing('1', 130.0)]);
        $result = $this->runner->check($this->watch->refresh());

        self::assertSame([], $result->events);
        self::assertSame(100.0, $this->watch->listings()->sole()->lowest_price);
    }

    public function test_a_listing_losing_its_price_is_not_a_drop(): void
    {
        $this->marketplace->queue([self::listing('1', 100.0)]);
        $this->runner->check($this->watch);

        $this->marketplace->queue([self::listing('1', null)]);

        self::assertSame([], $this->runner->check($this->watch->refresh())->events);
    }

    public function test_listings_outside_the_filters_are_kept_but_not_notified(): void
    {
        $this->watch->update([
            'max_price' => 150,
            'max_distance_km' => 30,
            'exclude_keywords' => ['defekt'],
            'include_keywords' => ['pinguino'],
        ]);
        $this->marketplace->queue([self::listing('0', 10.0)]);
        $this->runner->check($this->watch);

        $this->marketplace->queue([
            self::listing('expensive', 400.0),
            self::listing('far', 100.0, distanceKm: 80),
            self::listing('broken', 100.0, description: 'Gerät ist DEFEKT'),
            self::listing('other', 100.0, title: 'Klimagerät Comfee'),
            self::listing('good', 100.0),
            self::listing('no-price', null),
        ]);
        $result = $this->runner->check($this->watch->refresh());

        self::assertSame(['good', 'no-price'], \array_map(fn ($e) => $e->listing->external_id, $result->events));
        self::assertSame(7, $this->watch->listings()->count());
        self::assertFalse($this->watch->listings()->where('external_id', 'far')->sole()->matches_filters);
    }

    public function test_turned_off_notification_kinds_stay_quiet(): void
    {
        $this->watch->update(['notify_new_listings' => false, 'notify_price_drops' => false]);
        $this->marketplace->queue([self::listing('1', 100.0)]);
        $this->runner->check($this->watch);

        $this->marketplace->queue([self::listing('1', 50.0), self::listing('2', 50.0)]);

        self::assertSame([], $this->runner->check($this->watch->refresh())->events);
        Notification::assertNothingSent();
    }

    public function test_a_successful_check_clears_earlier_errors(): void
    {
        $this->watch->update(['last_error' => 'old', 'consecutive_failures' => 3, 'blocked_until' => now()->subMinute()]);
        $this->marketplace->queue([]);

        $this->runner->check($this->watch);

        $watch = $this->watch->refresh();
        self::assertNull($watch->last_error);
        self::assertSame(0, $watch->consecutive_failures);
        self::assertNull($watch->blocked_until);
        self::assertSameMoment(now(), $watch->last_checked_at);
    }

    public function test_a_block_pauses_the_watch_with_a_doubling_backoff(): void
    {
        $this->marketplace->queue(new MarketplaceBlockedException('blocked'));
        $first = $this->runner->check($this->watch);

        self::assertTrue($first->blocked);
        self::assertSameMoment(now()->addMinutes(30), $this->watch->refresh()->blocked_until);

        $this->marketplace->queue(new MarketplaceBlockedException('blocked'));
        $this->runner->check($this->watch->refresh());

        self::assertSameMoment(now()->addMinutes(60), $this->watch->refresh()->blocked_until);
        self::assertSame(2, $this->watch->refresh()->consecutive_failures);
    }

    public function test_a_longer_retry_after_than_the_backoff_is_honoured(): void
    {
        $this->marketplace->queue(new MarketplaceBlockedException('blocked', retryAfterSeconds: 4 * 3600));

        $this->runner->check($this->watch);

        self::assertSameMoment(now()->addHours(4), $this->watch->refresh()->blocked_until);
    }

    public function test_the_backoff_is_capped_at_a_day(): void
    {
        $this->watch->update(['consecutive_failures' => 20]);
        $this->marketplace->queue(new MarketplaceBlockedException('blocked'));

        $this->runner->check($this->watch);

        self::assertSameMoment(now()->addDay(), $this->watch->refresh()->blocked_until);
    }

    public function test_a_failure_is_recorded_without_pausing_the_watch(): void
    {
        $this->marketplace->queue(new MarketplaceException('HTTP 503'));

        $result = $this->runner->check($this->watch);

        self::assertFalse($result->ok);
        self::assertFalse($result->blocked);
        $watch = $this->watch->refresh();
        self::assertSame('HTTP 503', $watch->last_error);
        self::assertNull($watch->blocked_until);
        self::assertSame(1, $watch->consecutive_failures);
        self::assertNull($watch->last_succeeded_at);
    }

    public function test_only_a_successful_check_moves_the_last_success(): void
    {
        $this->marketplace->queue([self::listing('1', 100.0)]);
        $this->runner->check($this->watch);
        self::assertSameMoment(now(), $this->watch->refresh()->last_succeeded_at);
        $succeeded = now()->toImmutable();

        Carbon::setTestNow(now()->addHour());
        $this->marketplace->queue(new MarketplaceException('HTTP 503'));
        $this->runner->check($this->watch->refresh());

        self::assertSameMoment($succeeded, $this->watch->refresh()->last_succeeded_at);
        self::assertSame(1, $this->watch->listings()->stillListed()->count(), 'A failed check must not make listings look gone.');
    }

    public function test_a_listing_missing_from_the_last_successful_check_is_gone(): void
    {
        $this->marketplace->queue([self::listing('1', 100.0), self::listing('2', 100.0)]);
        $this->runner->check($this->watch);

        Carbon::setTestNow(now()->addHour());
        $this->marketplace->queue([self::listing('2', 100.0)]);
        $this->runner->check($this->watch->refresh());

        self::assertSame(['2'], $this->watch->listings()->stillListed()->pluck('external_id')->all());
        self::assertSame(['1'], $this->watch->listings()->gone()->pluck('external_id')->all());
    }

    public function test_the_url_radius_limits_distance_when_no_max_distance_is_set(): void
    {
        // The factory URL ends in r50: a 50 km radius around 55116.
        self::assertNull($this->watch->max_distance_km);

        self::assertTrue($this->runner->matches($this->watch, self::listing('near', 100.0, distanceKm: 50)));
        self::assertFalse($this->runner->matches($this->watch, self::listing('padding', 100.0, distanceKm: 469)));
        self::assertSame('Further than 50 km away.', $this->runner->mismatch($this->watch, self::listing('padding', 100.0, distanceKm: 469)));
    }

    public function test_an_explicit_max_distance_wins_over_the_url_radius(): void
    {
        $this->watch->update(['max_distance_km' => 100]);

        self::assertTrue($this->runner->matches($this->watch, self::listing('x', 100.0, distanceKm: 80)));
    }

    public function test_require_price_skips_listings_without_one(): void
    {
        self::assertTrue($this->runner->matches($this->watch, self::listing('vb', null)));

        $this->watch->update(['require_price' => true]);

        self::assertSame('No price given.', $this->runner->mismatch($this->watch, self::listing('vb', null)));
        self::assertTrue($this->runner->matches($this->watch, self::listing('priced', 80.0)));
    }

    public function test_the_mismatch_names_the_excluded_word(): void
    {
        $this->watch->update(['exclude_keywords' => ['defekt'], 'max_price' => 150]);

        self::assertSame('Mentions “defekt”.', $this->runner->mismatch($this->watch, self::listing('x', 100.0, description: 'Leider DEFEKT')));
        self::assertSame('Above the maximum price.', $this->runner->mismatch($this->watch, self::listing('y', 200.0)));
        self::assertNull($this->runner->mismatch($this->watch, self::listing('z', 100.0)));
    }

    public function test_a_preview_scores_listings_without_storing_or_notifying(): void
    {
        $this->watch->update(['max_price' => 150]);
        $this->marketplace->queue([self::listing('cheap', 100.0), self::listing('dear', 300.0)]);

        $rows = $this->runner->preview($this->watch);

        self::assertSame([null, 'Above the maximum price.'], \array_column($rows, 'mismatch'));
        self::assertSame(0, $this->watch->listings()->count());
        self::assertNull($this->watch->refresh()->last_checked_at);
        Notification::assertNothingSent();
    }

    public function test_rescoring_applies_changed_filters_to_stored_listings_quietly(): void
    {
        $this->marketplace->queue([
            self::listing('ok', 100.0),
            self::listing('broken', 100.0, description: 'Kompressor defekt'),
            self::listing('dear', 300.0),
        ]);
        $this->runner->check($this->watch);
        self::assertSame(3, $this->watch->listings()->where('matches_filters', true)->count());

        $this->watch->update(['exclude_keywords' => ['defekt'], 'max_price' => 150]);
        $changed = $this->runner->rescore($this->watch->refresh());

        self::assertSame(2, $changed);
        self::assertSame(['ok'], $this->watch->listings()->where('matches_filters', true)->pluck('external_id')->all());
        Notification::assertNothingSent();

        $this->watch->update(['exclude_keywords' => [], 'max_price' => null]);
        self::assertSame(2, $this->runner->rescore($this->watch->refresh()));
    }
}

/**
 * Hands out queued results in order and fails loudly when asked for more than was queued.
 */
final class FakeMarketplace implements MarketplaceSearch
{
    /** @var list<list<MarketplaceListingDto>|MarketplaceException> */
    private array $queue = [];

    private int $served = 0;

    /**
     * @param  list<MarketplaceListingDto>|MarketplaceException  $result
     */
    public function queue(array|MarketplaceException $result): void
    {
        $this->queue[] = $result;
    }

    public function search(string $searchUrl): array
    {
        $next = \array_shift($this->queue)
            ?? throw new RuntimeException(\sprintf('FakeMarketplace ran out of results for %s after %d served.', $searchUrl, $this->served));
        $this->served++;

        if ($next instanceof MarketplaceException) {
            throw $next;
        }

        return $next;
    }
}
