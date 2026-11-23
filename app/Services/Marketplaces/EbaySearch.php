<?php

declare(strict_types=1);

namespace App\Services\Marketplaces;

use App\Dto\MarketplaceListingDto;
use App\Enums\Marketplace;
use App\Exceptions\MarketplaceBlockedException;
use App\Exceptions\MarketplaceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Runs an ebay.de search through eBay's official Browse API instead of scraping the site,
 * which blocks scrapers aggressively. Needs a free production keyset from developer.ebay.com.
 */
final class EbaySearch implements MarketplaceSearch
{
    private const string TOKEN_URL = 'https://api.ebay.com/identity/v1/oauth2/token';

    private const string SEARCH_URL = 'https://api.ebay.com/buy/browse/v1/item_summary/search';

    private const string TOKEN_CACHE_KEY = 'search_watches.ebay_token';

    public function __construct(
        private readonly ?string $clientId = null,
        private readonly ?string $clientSecret = null,
    ) {}

    public static function fromConfig(): self
    {
        $id = config('services.ebay.client_id');
        $secret = config('services.ebay.client_secret');

        return new self(\is_string($id) ? $id : null, \is_string($secret) ? $secret : null);
    }

    public function isConfigured(): bool
    {
        return $this->clientId !== null && $this->clientId !== ''
            && $this->clientSecret !== null && $this->clientSecret !== '';
    }

    public function search(string $searchUrl): array
    {
        if (Marketplace::fromSearchUrl($searchUrl) !== Marketplace::Ebay) {
            throw new MarketplaceException(\sprintf('Not an ebay.de search URL: %s', $searchUrl));
        }

        if (! $this->isConfigured()) {
            throw new MarketplaceException('eBay search watches need EBAY_CLIENT_ID and EBAY_CLIENT_SECRET.');
        }

        $response = $this->send(fn () => Http::withToken($this->token())
            ->withHeaders(['X-EBAY-C-MARKETPLACE-ID' => 'EBAY_DE', 'Accept-Language' => 'de-DE'])
            ->connectTimeout(10)
            ->timeout(30)
            ->get(self::SEARCH_URL, self::queryFromSearchUrl($searchUrl)));

        if ($response->status() === 401) {
            Cache::forget(self::TOKEN_CACHE_KEY);
        }

        $this->assertOk($response);

        $items = $response->json('itemSummaries');

        return \is_array($items) ? $this->parseItems($items) : [];
    }

    /**
     * Translate the parts of an ebay.de search link the Browse API understands.
     *
     * @return array<string, string>
     */
    public static function queryFromSearchUrl(string $searchUrl): array
    {
        \parse_str((string) \parse_url($searchUrl, \PHP_URL_QUERY), $query);
        $get = static fn (string $key): string => isset($query[$key]) && \is_string($query[$key]) ? \trim($query[$key]) : '';

        $filters = match (true) {
            $get('LH_BIN') === '1' && $get('LH_Auction') !== '1' => ['buyingOptions:{FIXED_PRICE}'],
            $get('LH_Auction') === '1' && $get('LH_BIN') !== '1' => ['buyingOptions:{AUCTION}'],
            default => ['buyingOptions:{FIXED_PRICE|AUCTION}'],
        };

        $low = \str_replace(',', '.', $get('_udlo'));
        $high = \str_replace(',', '.', $get('_udhi'));
        if (\is_numeric($low) || \is_numeric($high)) {
            $filters[] = \sprintf('price:[%s..%s]', \is_numeric($low) ? $low : '', \is_numeric($high) ? $high : '');
            $filters[] = 'priceCurrency:EUR';
        }

        if (\preg_match('/^\d{1,5}(\|\d{1,5}){0,9}$/', $get('LH_ItemCondition')) === 1) {
            $filters[] = 'conditionIds:{'.$get('LH_ItemCondition').'}';
        }

        if ($get('LH_PrefLoc') === '1') {
            $filters[] = 'itemLocationCountry:DE';
        }

        $params = ['limit' => '50', 'sort' => 'newlyListed', 'filter' => \implode(',', $filters)];
        if ($get('_nkw') !== '') {
            $params['q'] = \mb_substr($get('_nkw'), 0, 100);
        }
        if (\preg_match('/^\d{1,10}$/', $get('_sacat')) === 1 && $get('_sacat') !== '0') {
            $params['category_ids'] = $get('_sacat');
        }

        return $params;
    }

    /**
     * @param  array<mixed>  $items
     * @return list<MarketplaceListingDto>
     */
    public function parseItems(array $items): array
    {
        $listings = [];
        foreach ($items as $item) {
            if (! \is_array($item)) {
                continue;
            }

            $id = $item['legacyItemId'] ?? null;
            if (! \is_string($id) || \preg_match('/^\d{1,20}$/', $id) !== 1 || isset($listings[$id])) {
                continue;
            }
            $title = \is_string($item['title'] ?? null) ? $item['title'] : $id;

            $auction = \in_array('AUCTION', (array) ($item['buyingOptions'] ?? []), true);
            $priceInfo = $auction && isset($item['currentBidPrice']) ? $item['currentBidPrice'] : ($item['price'] ?? null);
            $value = \is_array($priceInfo) && \is_numeric($priceInfo['value'] ?? null) ? (float) $priceInfo['value'] : null;
            $place = \is_array($item['itemLocation'] ?? null) ? $item['itemLocation'] : [];
            $image = \is_array($item['image'] ?? null) ? ($item['image']['imageUrl'] ?? null) : null;

            $listings[$id] = new MarketplaceListingDto(
                externalId: $id,
                title: \mb_substr($title, 0, 255),
                url: Marketplace::Ebay->baseUrl().'/itm/'.$id,
                price: $value,
                priceText: $value !== null ? \number_format($value, 2, ',', '.').' €'.($auction ? ' (Auktion)' : '') : null,
                location: \trim(\implode(' ', \array_filter([$place['postalCode'] ?? null, $place['city'] ?? null], \is_string(...)))) ?: null,
                image: \is_string($image) ? $image : null,
            );
        }

        return \array_values($listings);
    }

    private function token(): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if (\is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = $this->send(fn () => Http::asForm()
            ->withBasicAuth((string) $this->clientId, (string) $this->clientSecret)
            ->connectTimeout(10)
            ->timeout(30)
            ->post(self::TOKEN_URL, [
                'grant_type' => 'client_credentials',
                'scope' => 'https://api.ebay.com/oauth/api_scope',
            ]));

        if (\in_array($response->status(), [400, 401], true)) {
            throw new MarketplaceException('eBay did not accept the API keys. Check EBAY_CLIENT_ID and EBAY_CLIENT_SECRET.');
        }
        $this->assertOk($response);

        $token = $response->json('access_token');
        if (! \is_string($token) || $token === '') {
            throw new MarketplaceException('eBay returned no access token.');
        }

        $expiresIn = $response->json('expires_in');
        $ttl = \max(60, (\is_numeric($expiresIn) ? (int) $expiresIn : 7200) - 300);
        Cache::put(self::TOKEN_CACHE_KEY, $token, $ttl);

        return $token;
    }

    /**
     * @param  callable(): Response  $request
     */
    private function send(callable $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException $e) {
            throw new MarketplaceException('eBay could not be reached: '.$e->getMessage(), previous: $e);
        }
    }

    private function assertOk(Response $response): void
    {
        if ($response->status() === 429) {
            throw new MarketplaceBlockedException('eBay\'s API call limit is used up for now (HTTP 429).');
        }

        if (! $response->successful()) {
            throw new MarketplaceException(\sprintf('eBay answered HTTP %d.', $response->status()));
        }
    }
}
