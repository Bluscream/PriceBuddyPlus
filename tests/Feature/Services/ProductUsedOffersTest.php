<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Filament\Resources\ProductResource\Pages\ViewProduct;
use App\Filament\Resources\SearchWatchResource\Pages\EditSearchWatch;
use App\Models\Product;
use App\Models\SearchWatch;
use App\Models\SearchWatchListing;
use App\Models\User;
use App\Services\ProductUsedOffers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class ProductUsedOffersTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Product $product;

    private SearchWatch $watch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->product = Product::factory()->for($this->user)->create(['title' => 'DeLonghi Pinguino PAC N82']);
        $this->product->forceFill(['current_price' => 400.0])->saveQuietly();
        $this->watch = SearchWatch::factory()->for($this->user)->create(['name' => 'Pinguino near Mainz', 'last_succeeded_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function listing(?float $price, array $attributes = []): SearchWatchListing
    {
        return SearchWatchListing::factory()->for($this->watch)->create($attributes + [
            'price' => $price,
            'first_price' => $price,
            'last_seen_at' => now(),
        ]);
    }

    public function test_an_unlinked_product_has_no_used_offers_section(): void
    {
        $this->listing(100.0);

        self::assertFalse((new ProductUsedOffers($this->product))->hasLinkedWatches());
    }

    public function test_linked_watch_listings_are_offered_cheapest_first_against_the_new_price(): void
    {
        $this->watch->products()->attach($this->product);
        $dear = $this->listing(300.0);
        $cheap = $this->listing(100.0);
        $vb = $this->listing(null);
        $this->listing(50.0, ['matches_filters' => false]);
        $this->listing(60.0, ['last_seen_at' => now()->subDay()]);

        $offers = (new ProductUsedOffers($this->product))->offers();

        self::assertSame([$cheap->id, $dear->id, $vb->id], \array_map(static fn (array $o): int => $o['listing']->id, $offers));
        self::assertSame([75, 25, null], \array_column($offers, 'percentBelowNew'));
    }

    public function test_another_users_watch_never_supplies_offers(): void
    {
        $theirs = SearchWatch::factory()->create();
        $theirs->products()->attach($this->product);
        SearchWatchListing::factory()->for($theirs)->create(['last_seen_at' => now()]);

        self::assertSame([], (new ProductUsedOffers($this->product))->offers());
    }

    public function test_a_watch_is_linked_to_products_from_its_form(): void
    {
        $this->actingAs($this->user);

        Livewire::test(EditSearchWatch::class, ['record' => $this->watch->getRouteKey()])
            ->fillForm(['products' => [$this->product->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        self::assertSame([$this->product->id], $this->watch->products()->pluck('products.id')->all());
    }

    public function test_the_product_page_shows_used_offers(): void
    {
        $this->actingAs($this->user);
        $this->watch->products()->attach($this->product);
        $this->listing(100.0, ['title' => 'Pinguino gebraucht, top Zustand']);

        $page = Livewire::test(ViewProduct::class, ['record' => $this->product->getRouteKey()]);
        $page->assertSee('Used offers');
        $page->assertSee('Pinguino gebraucht, top Zustand');
        $page->assertSee('−75 % vs. new');
    }
}
