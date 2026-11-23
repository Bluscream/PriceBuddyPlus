<?php

declare(strict_types=1);

namespace App\Services\Marketplaces;

use App\Dto\MarketplaceListingDto;
use App\Enums\Marketplace;
use App\Exceptions\MarketplaceBlockedException;
use App\Exceptions\MarketplaceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Reads a kleinanzeigen.de search results page. The page is server-rendered, so a plain
 * HTTP request is enough; every listing is an `article[data-adid]`.
 */
final class KleinanzeigenSearch implements MarketplaceSearch
{
    private const int MAX_BODY_BYTES = 5_000_000;

    public function __construct(
        private readonly string $userAgent = 'Mozilla/5.0 (X11; Linux x86_64; rv:140.0) Gecko/20100101 Firefox/140.0',
    ) {}

    public function search(string $searchUrl): array
    {
        if (Marketplace::fromSearchUrl($searchUrl) !== Marketplace::Kleinanzeigen) {
            throw new MarketplaceException(\sprintf('Not a Kleinanzeigen search URL: %s', $searchUrl));
        }

        $body = $this->fetch($searchUrl);

        return $this->parse($body);
    }

    /**
     * @return list<MarketplaceListingDto>
     */
    public function parse(string $html): array
    {
        $crawler = new Crawler($html);
        $listings = [];
        $seen = [];

        $crawler->filter('article[data-adid]')->each(function (Crawler $article) use (&$listings, &$seen): void {
            $listing = $this->parseArticle($article);
            if ($listing !== null && ! isset($seen[$listing->externalId])) {
                $seen[$listing->externalId] = true;
                $listings[] = $listing;
            }
        });

        return $listings;
    }

    private function fetch(string $url): string
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => $this->userAgent,
                'Accept' => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'de-DE,de;q=0.9',
            ])
                ->connectTimeout(10)
                ->timeout(30)
                ->withOptions(['allow_redirects' => [
                    'max' => 3,
                    'protocols' => ['https'],
                    // A redirect away from the marketplace (a consent or captcha host) is a block.
                    'on_redirect' => static function (RequestInterface $request, mixed $response, UriInterface $uri): void {
                        if ($uri->getHost() !== 'www.kleinanzeigen.de') {
                            throw new MarketplaceBlockedException(\sprintf('Kleinanzeigen redirected to %s.', $uri->getHost()));
                        }
                    },
                ]])
                ->get($url);
        } catch (ConnectionException $e) {
            throw new MarketplaceException('Kleinanzeigen could not be reached: '.$e->getMessage(), previous: $e);
        }

        $this->assertUsable($response);

        return $response->body();
    }

    private function assertUsable(Response $response): void
    {
        $status = $response->status();
        if ($status === 403 || $status === 429) {
            $retryAfter = $response->header('Retry-After');

            throw new MarketplaceBlockedException(
                \sprintf('Kleinanzeigen is blocking requests (HTTP %d).', $status),
                \is_numeric($retryAfter) ? (int) $retryAfter : null,
            );
        }

        if ($status !== 200) {
            throw new MarketplaceException(\sprintf('Kleinanzeigen answered HTTP %d.', $status));
        }

        if (\strlen($response->body()) > self::MAX_BODY_BYTES) {
            throw new MarketplaceException('Kleinanzeigen returned an unexpectedly large page.');
        }
    }

    private function parseArticle(Crawler $article): ?MarketplaceListingDto
    {
        $id = \trim((string) $article->attr('data-adid'));
        $href = (string) ($article->attr('data-href') ?? '');
        $titleNode = $article->filter('h3');

        if (\preg_match('/^\d{1,20}$/', $id) !== 1 || ! \str_starts_with($href, '/s-anzeige/') || $titleNode->count() === 0) {
            return null;
        }

        $priceNode = $article->filter('p.font-strong.text-secondary');
        $priceText = $priceNode->count() > 0 ? \trim($priceNode->first()->text()) : null;
        $price = $priceText !== null ? PriceText::parse($priceText) : null;
        [$location, $distance] = $this->location($article);
        $image = $article->filter('img');
        $description = $article->filter('p.text-onSurfaceSubdued');

        return new MarketplaceListingDto(
            externalId: $id,
            title: \mb_substr(\trim($titleNode->text()), 0, 255),
            url: Marketplace::Kleinanzeigen->baseUrl().$href,
            price: $price?->amount,
            priceText: $priceText !== null && $priceText !== '' ? \mb_substr($priceText, 0, 64) : null,
            negotiable: $price !== null && $price->negotiable,
            location: $location,
            distanceKm: $distance,
            image: $image->count() > 0 ? $image->first()->attr('src') : null,
            description: $description->count() > 0 ? \trim($description->first()->text()) : null,
        );
    }

    /**
     * The location row is the parent of the location icon: "55116 Mainz" then "(12 km)".
     *
     * @return array{0: ?string, 1: ?int}
     */
    private function location(Crawler $article): array
    {
        $icon = $article->filter('svg[data-title="locationOutline"]');
        if ($icon->count() === 0) {
            return [null, null];
        }

        $spans = $icon->first()->ancestors()->first()->filter('span');
        $location = $spans->count() > 0 ? \trim($spans->first()->text()) : null;
        $distance = null;
        if ($spans->count() > 1 && \preg_match('/(\d+)\s*km/u', $spans->eq(1)->text(), $match) === 1) {
            $distance = (int) $match[1];
        }

        return [$location !== '' ? $location : null, $distance];
    }
}
