<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Exceptions\MarketplaceBlockedException;
use App\Exceptions\MarketplaceException;
use App\Services\Marketplaces\EbaySearch;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Traits\SearchWatchAssertions;

final class EbaySearchTest extends TestCase
{
    use SearchWatchAssertions;

    private const string SEARCH_URL = 'https://www.ebay.de/sch/i.html?_nkw=pinguino&_udhi=150&LH_BIN=1&LH_PrefLoc=1&_sacat=0';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_a_search_link_is_translated_into_browse_api_parameters(): void
    {
        self::assertSame([
            'limit' => '50',
            'sort' => 'newlyListed',
            'filter' => 'buyingOptions:{FIXED_PRICE},price:[..150],priceCurrency:EUR,itemLocationCountry:DE',
            'q' => 'pinguino',
        ], EbaySearch::queryFromSearchUrl(self::SEARCH_URL));
    }

    public function test_listings_are_read_from_the_browse_api_with_an_application_token(): void
    {
        Http::fake([
            'api.ebay.com/identity/*' => Http::response(['access_token' => 'app-token', 'expires_in' => 7200]),
            'api.ebay.com/buy/*' => Http::response(['itemSummaries' => [
                [
                    'legacyItemId' => '200000000001',
                    'title' => 'Pinguino PAC EX93',
                    'price' => ['value' => '129.00', 'currency' => 'EUR'],
                    'buyingOptions' => ['FIXED_PRICE'],
                    'itemLocation' => ['postalCode' => '10001', 'city' => 'Musterstadt', 'country' => 'DE'],
                    'image' => ['imageUrl' => 'https://img.example.test/ebay-1.jpg'],
                ],
                [
                    'legacyItemId' => '200000000002',
                    'title' => 'Pinguino Auktion',
                    'price' => ['value' => '300.00'],
                    'currentBidPrice' => ['value' => '41.50'],
                    'buyingOptions' => ['AUCTION'],
                ],
                ['legacyItemId' => 'not-a-number', 'title' => 'Ignored'],
            ]]),
        ]);

        $listings = (new EbaySearch('id', 'secret'))->search(self::SEARCH_URL);

        self::assertCount(2, $listings);
        self::assertSame('https://www.ebay.de/itm/200000000001', $listings[0]->url);
        self::assertSame(129.0, $listings[0]->price);
        self::assertSame('10001 Musterstadt', $listings[0]->location);
        self::assertSame(41.5, $listings[1]->price, 'An auction shows its current bid.');
        self::assertStringContainsString('Auktion', (string) $listings[1]->priceText);
        Http::assertSent(fn ($request) => \str_contains(self::request($request)->url(), 'item_summary/search')
            && self::request($request)->hasHeader('Authorization', 'Bearer app-token')
            && self::request($request)->hasHeader('X-EBAY-C-MARKETPLACE-ID', 'EBAY_DE'));
    }

    public function test_the_application_token_is_reused_between_searches(): void
    {
        Http::fake([
            'api.ebay.com/identity/*' => Http::response(['access_token' => 'app-token', 'expires_in' => 7200]),
            'api.ebay.com/buy/*' => Http::response(['itemSummaries' => []]),
        ]);

        $search = new EbaySearch('id', 'secret');
        $search->search(self::SEARCH_URL);
        $search->search(self::SEARCH_URL);

        Http::assertSentCount(3);
    }

    public function test_missing_api_keys_fail_with_a_clear_message_and_no_request(): void
    {
        Http::fake();

        try {
            (new EbaySearch)->search(self::SEARCH_URL);
            self::fail('Missing keys must throw.');
        } catch (MarketplaceException $e) {
            self::assertStringContainsString('EBAY_CLIENT_ID', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_an_exhausted_call_limit_is_a_block(): void
    {
        Http::fake([
            'api.ebay.com/identity/*' => Http::response(['access_token' => 'app-token', 'expires_in' => 7200]),
            'api.ebay.com/buy/*' => Http::response([], 429),
        ]);

        $this->expectException(MarketplaceBlockedException::class);
        (new EbaySearch('id', 'secret'))->search(self::SEARCH_URL);
    }

    public function test_rejected_api_keys_are_reported(): void
    {
        Http::fake(['api.ebay.com/identity/*' => Http::response(['error' => 'invalid_client'], 401)]);

        $this->expectExceptionMessage('did not accept the API keys');
        (new EbaySearch('id', 'wrong'))->search(self::SEARCH_URL);
    }
}
