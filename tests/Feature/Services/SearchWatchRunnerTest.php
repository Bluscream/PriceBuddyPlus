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
