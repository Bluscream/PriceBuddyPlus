<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Dashboard;

use App\Filament\Pages\HomeDashboard;
use App\Filament\Widgets\ProductStats;
use App\Models\SearchWatch;
use App\Models\SearchWatchListing;
use App\Models\User;
use App\Services\Dashboard\SearchWatchSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

final class SearchWatchSectionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private SearchWatch $watch;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-23 12:00:00');
        $this->user = User::factory()->create();
        $this->watch = SearchWatch::factory()->for($this->user)->create([
            'name' => 'Pinguino near Mainz',
            'last_checked_at' => now(),
            'last_succeeded_at' => now(),
            'listings_viewed_at' => now()->subHour(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function listing(float $price, string $firstSeen = '-1 day', array $attributes = []): SearchWatchListing
    {
        return SearchWatchListing::factory()->for($this->watch)->create($attributes + [
            'price' => $price,
            'first_price' => $price,
            'first_seen_at' => now()->modify($firstSeen),
            'last_seen_at' => now(),
        ]);
    }

    public function test_watches_count_matching_listings_still_listed_and_new_since_the_last_visit(): void
    {
        $this->listing(100.0, '-2 days');
        $this->listing(100.0, '-10 minutes');
        $this->listing(100.0, '-10 minutes', ['matches_filters' => false]);
        $this->listing(100.0, '-10 minutes', ['last_seen_at' => now()->subDay()]);
        SearchWatch::factory()->create();

        $watches = (new SearchWatchSection($this->user))->watches();

        self::assertCount(1, $watches);
        self::assertSame(2, $watches->sole()->matching_listings_count);
        self::assertSame(1, $watches->sole()->new_listings_count);
    }

    public function test_watches_with_matches_come_first(): void
    {
        $empty = SearchWatch::factory()->for($this->user)->create(['name' => 'Aaa nothing here']);
        $this->listing(100.0);

        $order = (new SearchWatchSection($this->user))->watches()->pluck('id')->all();

        self::assertSame([$this->watch->id, $empty->id], $order);
    }

    public function test_latest_finds_are_the_newest_matching_listings_still_listed(): void
    {
        $older = $this->listing(100.0, '-2 days');
        $newer = $this->listing(100.0, '-1 hour');
        $this->listing(100.0, '-5 minutes', ['matches_filters' => false]);
        $this->listing(100.0, '-5 minutes', ['last_seen_at' => now()->subDay()]);

        $finds = (new SearchWatchSection($this->user))->latestFinds();

        self::assertSame([$newer->id, $older->id], $finds->pluck('id')->all());
    }

    public function test_best_deals_rank_listings_below_their_watch_median(): void
    {
        $cheapest = $this->listing(50.0);
        $cheap = $this->listing(80.0);
        $this->listing(100.0);
        $this->listing(120.0);
        $this->listing(150.0);

        $deals = (new SearchWatchSection($this->user))->bestDeals();

        self::assertSame([$cheapest->id, $cheap->id], \array_map(static fn (array $d): int => $d['listing']->id, $deals));
        self::assertSame(100.0, $deals[0]['median']);
        self::assertSame(50, $deals[0]['percentBelow']);
    }

    public function test_too_few_prices_give_no_deals(): void
    {
        $this->listing(10.0);
        $this->listing(100.0);
        $this->listing(120.0);

        self::assertSame([], (new SearchWatchSection($this->user))->bestDeals());
    }

    public function test_the_dashboard_renders_the_section_and_can_hide_it(): void
    {
        $this->actingAs($this->user);
        $this->listing(100.0, '-10 minutes', ['title' => 'Pinguino PAC N82 wie neu']);

        $widget = Livewire::test(ProductStats::class);
        $widget->assertSee('Marketplace finds');
        $widget->assertSee('Pinguino near Mainz');
        $widget->assertSee('Pinguino PAC N82 wie neu');
        $widget->assertSee('1 new');

        Livewire::test(HomeDashboard::class)->callAction('customize', data: [
            'stat_bar' => false,
            'buy_now' => true,
            'recently_dropped' => false,
            'needs_attention' => false,
            'search_watches' => false,
        ]);

        Livewire::test(ProductStats::class)->assertDontSee('Marketplace finds');
    }
}
