<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\Marketplace;
use App\Filament\Resources\SearchWatchResource;
use App\Filament\Resources\SearchWatchResource\Pages\CreateSearchWatch;
use App\Filament\Resources\SearchWatchResource\Pages\EditSearchWatch;
use App\Filament\Resources\SearchWatchResource\Pages\ListSearchWatches;
use App\Filament\Resources\SearchWatchResource\RelationManagers\ListingsRelationManager;
use App\Models\SearchWatch;
use App\Models\SearchWatchListing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

final class SearchWatchResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_index_and_create_pages_render(): void
    {
        $this->get(SearchWatchResource::getUrl('index'))->assertSuccessful();
        $this->get(SearchWatchResource::getUrl('create'))->assertSuccessful();
    }

    public function test_a_watch_is_created_for_the_current_user_with_its_marketplace_detected(): void
    {
        Livewire::test(CreateSearchWatch::class)
            ->fillForm([
                'name' => 'Pinguino near Mainz',
                'search_url' => '  https://www.kleinanzeigen.de/s-55116/pinguino/k0l5315r50 ',
                'max_price' => 150,
                'exclude_keywords' => ['defekt'],
                'check_interval_minutes' => 20,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $watch = SearchWatch::query()->sole();
        self::assertSame($this->user->id, $watch->user_id);
        self::assertSame(Marketplace::Kleinanzeigen, $watch->marketplace);
        self::assertSame('https://www.kleinanzeigen.de/s-55116/pinguino/k0l5315r50', $watch->search_url);
        self::assertSame(['defekt'], $watch->exclude_keywords);
        self::assertSame(150.0, $watch->max_price);
    }

    public function test_a_single_listing_url_is_rejected(): void
    {
        Livewire::test(CreateSearchWatch::class)
            ->fillForm([
                'name' => 'Wrong',
                'search_url' => 'https://www.kleinanzeigen.de/s-anzeige/foo/123-176-1',
                'check_interval_minutes' => 30,
            ])
            ->call('create')
            ->assertHasFormErrors(['search_url']);

        self::assertSame(0, SearchWatch::query()->count());
    }

    public function test_a_maximum_below_the_minimum_price_is_rejected(): void
    {
        Livewire::test(CreateSearchWatch::class)
            ->fillForm([
                'name' => 'Backwards',
                'search_url' => 'https://www.kleinanzeigen.de/s-55116/pinguino/k0',
                'min_price' => 200,
                'max_price' => 100,
                'check_interval_minutes' => 30,
            ])
            ->call('create')
            ->assertHasFormErrors(['max_price']);
    }

    public function test_an_interval_below_the_floor_is_rejected(): void
    {
        Livewire::test(CreateSearchWatch::class)
            ->fillForm([
                'name' => 'Too eager',
                'search_url' => 'https://www.kleinanzeigen.de/s-55116/pinguino/k0',
                'check_interval_minutes' => 1,
            ])
            ->call('create')
            ->assertHasFormErrors(['check_interval_minutes']);
    }

    public function test_the_list_shows_only_the_users_own_watches(): void
    {
        $mine = SearchWatch::factory()->for($this->user)->create();
        $theirs = SearchWatch::factory()->create();

        Livewire::test(ListSearchWatches::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    public function test_another_users_watch_cannot_be_opened(): void
    {
        $theirs = SearchWatch::factory()->create();

        $this->get(SearchWatchResource::getUrl('edit', ['record' => $theirs]))->assertForbidden();
    }

    public function test_check_now_saves_a_baseline_from_the_marketplace(): void
    {
        Http::fake(['www.kleinanzeigen.de/*' => Http::response(
            (string) \file_get_contents(\dirname(__DIR__, 2).'/Fixtures/Kleinanzeigen/search-results.html')
        )]);
        $watch = SearchWatch::factory()->for($this->user)->create();

        Livewire::test(EditSearchWatch::class, ['record' => $watch->getRouteKey()])
            ->callAction('check_now')
            ->assertNotified();

        self::assertSame(5, $watch->listings()->count());
        self::assertNotNull($watch->refresh()->last_checked_at);
    }

    public function test_the_listings_table_shows_matching_listings_by_default(): void
    {
        $watch = SearchWatch::factory()->for($this->user)->create();
        $match = SearchWatchListing::factory()->for($watch)->create();
        $filtered = SearchWatchListing::factory()->for($watch)->create(['matches_filters' => false]);

        $table = Livewire::test(ListingsRelationManager::class, ['ownerRecord' => $watch, 'pageClass' => EditSearchWatch::class]);
        $table->assertCanSeeTableRecords([$match]);
        $table->assertCanNotSeeTableRecords([$filtered]);
    }

    public function test_listings_gone_from_the_last_successful_check_are_hidden_by_default(): void
    {
        $watch = SearchWatch::factory()->for($this->user)->create(['last_succeeded_at' => now()]);
        $listed = SearchWatchListing::factory()->for($watch)->create(['last_seen_at' => now()]);
        $gone = SearchWatchListing::factory()->for($watch)->create(['last_seen_at' => now()->subDay()]);

        $table = Livewire::test(ListingsRelationManager::class, ['ownerRecord' => $watch, 'pageClass' => EditSearchWatch::class]);
        $table->assertCanSeeTableRecords([$listed])->assertCanNotSeeTableRecords([$gone]);
        $table->filterTable('still_listed', false)->assertCanSeeTableRecords([$gone])->assertCanNotSeeTableRecords([$listed]);
    }

    public function test_the_has_price_filter_separates_priced_listings(): void
    {
        $watch = SearchWatch::factory()->for($this->user)->create();
        $priced = SearchWatchListing::factory()->for($watch)->create();
        $priceless = SearchWatchListing::factory()->for($watch)->create(['price' => null, 'price_text' => 'VB']);

        Livewire::test(ListingsRelationManager::class, ['ownerRecord' => $watch, 'pageClass' => EditSearchWatch::class])
            ->filterTable('has_price', true)
            ->assertCanSeeTableRecords([$priced])
            ->assertCanNotSeeTableRecords([$priceless]);
    }

    public function test_opening_a_watch_marks_listings_since_the_previous_visit_as_new(): void
    {
        $watch = SearchWatch::factory()->for($this->user)->create(['listings_viewed_at' => now()->subHour()]);
        $old = SearchWatchListing::factory()->for($watch)->create(['first_seen_at' => now()->subDay()]);
        $new = SearchWatchListing::factory()->for($watch)->create(['first_seen_at' => now()->subMinutes(5)]);

        Livewire::test(ListingsRelationManager::class, ['ownerRecord' => $watch, 'pageClass' => EditSearchWatch::class])
            ->filterTable('new_since_visit')
            ->assertCanSeeTableRecords([$new])
            ->assertCanNotSeeTableRecords([$old]);

        self::assertTrue($watch->refresh()->listings_viewed_at?->greaterThan(now()->subMinute()));
    }

    public function test_saving_changed_filters_rescores_stored_listings(): void
    {
        $watch = SearchWatch::factory()->for($this->user)->create();
        $broken = SearchWatchListing::factory()->for($watch)->create(['description' => 'Kompressor defekt']);
        $fine = SearchWatchListing::factory()->for($watch)->create(['description' => 'Wie neu']);

        Livewire::test(EditSearchWatch::class, ['record' => $watch->getRouteKey()])
            ->fillForm(['exclude_keywords' => ['defekt']])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        self::assertFalse($broken->refresh()->matches_filters);
        self::assertTrue($fine->refresh()->matches_filters);
    }

    public function test_preview_shows_what_unsaved_filters_would_match_without_saving(): void
    {
        Http::fake(['www.kleinanzeigen.de/*' => Http::response(
            (string) \file_get_contents(\dirname(__DIR__, 2).'/Fixtures/Kleinanzeigen/search-results.html')
        )]);
        $watch = SearchWatch::factory()->for($this->user)->create();

        Livewire::test(EditSearchWatch::class, ['record' => $watch->getRouteKey()])
            ->fillForm(['max_price' => 1])
            ->mountAction('preview')
            ->assertSee('Above the maximum price.');

        self::assertSame(0, $watch->listings()->count());
        self::assertNull($watch->refresh()->max_price);
    }

    public function test_preview_on_the_create_page_needs_a_search_url(): void
    {
        Livewire::test(CreateSearchWatch::class)
            ->mountAction('preview')
            ->assertSee('Enter a Kleinanzeigen or eBay.de search results URL first.');
    }
}
