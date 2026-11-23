<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Exceptions\MarketplaceException;
use App\Models\Price;
use App\Models\SearchWatch;
use App\Models\SearchWatchListing;
use App\Models\Store;
use App\Models\User;
use App\Services\SearchWatchListingTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SearchWatchListingTrackerTest extends TestCase
{
    use RefreshDatabase;

    private const string AD_PAGE = <<<'HTML'
        <html><head><meta property="og:image" content="https://img.example.test/ad.jpg"></head>
        <body><h1 id="viewad-title">Pinguino PAC EX93</h1><h2 id="viewad-price">180 € VB</h2></body></html>
        HTML;

    private SearchWatchListing $listing;

    protected function setUp(): void
    {
        parent::setUp();
        $watch = SearchWatch::factory()->for(User::factory())->create();
        $this->listing = SearchWatchListing::factory()->for($watch)->create([
            'url' => 'https://www.kleinanzeigen.de/s-anzeige/pinguino/1000000001-176-1',
        ]);
    }

    public function test_a_listing_becomes_a_product_using_the_bundled_kleinanzeigen_store(): void
    {
        Http::fake(['www.kleinanzeigen.de/*' => Http::response(self::AD_PAGE)]);

        $url = (new SearchWatchListingTracker)->track($this->listing);

        $store = Store::query()->where('slug', 'kleinanzeigen')->sole();
        self::assertSame($store->id, $url->store_id);
        self::assertSame('EUR', $store->currency);
        $product = $url->product;
        self::assertNotNull($product);
        self::assertSame($this->listing->searchWatch->user_id, $product->user_id);
        self::assertSame(180.0, Price::query()->where('url_id', $url->id)->sole()->price);
        self::assertTrue($product->notify_on_change);
        self::assertSame($url->id, $this->listing->refresh()->url_id);
    }

    public function test_tracking_twice_returns_the_same_url(): void
    {
        Http::fake(['www.kleinanzeigen.de/*' => Http::response(self::AD_PAGE)]);
        $tracker = new SearchWatchListingTracker;

        $first = $tracker->track($this->listing);
        $second = $tracker->track($this->listing->refresh());

        self::assertSame($first->id, $second->id);
        self::assertSame(1, Store::query()->where('slug', 'kleinanzeigen')->count());
    }

    public function test_an_unreadable_listing_page_is_reported(): void
    {
        Http::fake(['www.kleinanzeigen.de/*' => Http::response('<html><body>Gone</body></html>')]);

        $this->expectException(MarketplaceException::class);
        (new SearchWatchListingTracker)->track($this->listing);
    }
}
