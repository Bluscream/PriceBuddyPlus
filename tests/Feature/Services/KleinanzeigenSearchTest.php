<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Exceptions\MarketplaceBlockedException;
use App\Exceptions\MarketplaceException;
use App\Services\Marketplaces\KleinanzeigenSearch;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Traits\SearchWatchAssertions;

final class KleinanzeigenSearchTest extends TestCase
{
    use SearchWatchAssertions;

    private const string SEARCH_URL = 'https://www.kleinanzeigen.de/s-10001/pinguino/k0l1r50';

    private static function fixture(): string
    {
        return (string) \file_get_contents(\dirname(__DIR__, 2).'/Fixtures/Kleinanzeigen/search-results.html');
    }

    public function test_every_listing_on_a_results_page_is_read_once(): void
    {
        $listings = (new KleinanzeigenSearch)->parse(self::fixture());

        self::assertSame(['1000000001', '1000000002', '1000000003', '1000000004', '1000000005'], \array_map(fn ($l) => $l->externalId, $listings));
    }

    public function test_a_listing_carries_title_url_price_location_distance_and_image(): void
    {
        $first = (new KleinanzeigenSearch)->parse(self::fixture())[0];

        self::assertSame("De'Longhi Pinguino mobiles Klimagerät", $first->title);
        self::assertSame('https://www.kleinanzeigen.de/s-anzeige/pinguino-mobiles-klimageraet/1000000001-176-1000', $first->url);
        self::assertSame(220.0, $first->price);
        self::assertSame('220 € VB', $first->priceText);
        self::assertTrue($first->negotiable);
        self::assertSame('10001 Musterstadt', $first->location);
        self::assertSame(37, $first->distanceKm);
        self::assertSame('https://img.example.test/listing-1000000001.jpg', $first->image);
        self::assertSame('Placeholder description one.', $first->description);
    }

    public function test_the_current_price_wins_over_a_struck_out_old_price(): void
    {
        $listing = (new KleinanzeigenSearch)->parse(self::fixture())[2];

        self::assertSame(400.0, $listing->price);
        self::assertSame('400 € VB', $listing->priceText);
    }

    public function test_a_negotiable_listing_without_amount_has_no_price(): void
    {
        $listing = (new KleinanzeigenSearch)->parse(self::fixture())[3];

        self::assertNull($listing->price);
        self::assertTrue($listing->negotiable);
    }

    public function test_a_free_listing_costs_nothing(): void
    {
        self::assertSame(0.0, (new KleinanzeigenSearch)->parse(self::fixture())[4]->price);
    }

    public function test_search_fetches_the_page_with_a_browser_like_request(): void
    {
        Http::fake(['www.kleinanzeigen.de/*' => Http::response(self::fixture())]);

        $listings = (new KleinanzeigenSearch)->search(self::SEARCH_URL);

        self::assertCount(5, $listings);
        Http::assertSent(fn ($request) => self::request($request)->url() === self::SEARCH_URL
            && self::request($request)->hasHeader('Accept-Language', 'de-DE,de;q=0.9'));
    }

    public function test_a_rate_limit_is_reported_as_a_block_with_the_requested_delay(): void
    {
        Http::fake(['www.kleinanzeigen.de/*' => Http::response('', 429, ['Retry-After' => '600'])]);

        try {
            (new KleinanzeigenSearch)->search(self::SEARCH_URL);
            self::fail('A 429 must throw.');
        } catch (MarketplaceBlockedException $e) {
            self::assertSame(600, $e->retryAfterSeconds);
        }
    }

    public function test_a_forbidden_response_is_reported_as_a_block(): void
    {
        Http::fake(['www.kleinanzeigen.de/*' => Http::response('', 403)]);

        $this->expectException(MarketplaceBlockedException::class);
        (new KleinanzeigenSearch)->search(self::SEARCH_URL);
    }

    public function test_a_server_error_is_a_plain_failure_not_a_block(): void
    {
        Http::fake(['www.kleinanzeigen.de/*' => Http::response('', 503)]);

        try {
            (new KleinanzeigenSearch)->search(self::SEARCH_URL);
            self::fail('A 503 must throw.');
        } catch (MarketplaceException $e) {
            self::assertNotInstanceOf(MarketplaceBlockedException::class, $e);
        }
    }

    public function test_a_url_for_another_site_is_refused_without_a_request(): void
    {
        Http::fake();

        $this->expectException(MarketplaceException::class);
        try {
            (new KleinanzeigenSearch)->search('https://example.com/s-foo');
        } finally {
            Http::assertNothingSent();
        }
    }
}
