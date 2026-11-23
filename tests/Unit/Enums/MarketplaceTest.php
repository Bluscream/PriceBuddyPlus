<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\Marketplace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarketplaceTest extends TestCase
{
    /**
     * @return array<string, array{string, ?Marketplace}>
     */
    public static function urls(): array
    {
        return [
            'kleinanzeigen search' => ['https://www.kleinanzeigen.de/s-55116/pinguino/k0l5315r50', Marketplace::Kleinanzeigen],
            'kleinanzeigen search with price' => ['https://www.kleinanzeigen.de/s-preis::150/pinguino/k0', Marketplace::Kleinanzeigen],
            'kleinanzeigen single listing' => ['https://www.kleinanzeigen.de/s-anzeige/foo/123-176-1', null],
            'kleinanzeigen over http' => ['http://www.kleinanzeigen.de/s-55116/pinguino/k0', null],
            'kleinanzeigen lookalike host' => ['https://www.kleinanzeigen.de.evil.test/s-55116/x', null],
            'ebay search' => ['https://www.ebay.de/sch/i.html?_nkw=pinguino', Marketplace::Ebay],
            'ebay without www' => ['https://ebay.de/sch/i.html?_nkw=pinguino', Marketplace::Ebay],
            'ebay item' => ['https://www.ebay.de/itm/123', null],
            'other host' => ['https://example.com/s-foo', null],
            'garbage' => ['not a url', null],
        ];
    }

    #[DataProvider('urls')]
    public function test_only_search_pages_on_known_hosts_are_accepted(string $url, ?Marketplace $expected): void
    {
        self::assertSame($expected, Marketplace::fromSearchUrl($url));
    }

    /**
     * @return array<string, array{Marketplace, string, ?int}>
     */
    public static function radii(): array
    {
        return [
            'query with location and radius' => [Marketplace::Kleinanzeigen, 'https://www.kleinanzeigen.de/s-55116/pinguino/k0l5315r50', 50],
            'category, query, location and radius' => [Marketplace::Kleinanzeigen, 'https://www.kleinanzeigen.de/s-haushaltsgeraete/55116/klimaanlage/k0c176l5315r100', 100],
            'category without a query' => [Marketplace::Kleinanzeigen, 'https://www.kleinanzeigen.de/s-haushaltsgeraete/55116/c176l5315r20', 20],
            'trailing slash' => [Marketplace::Kleinanzeigen, 'https://www.kleinanzeigen.de/s-55116/pinguino/k0l5315r5/', 5],
            'no location' => [Marketplace::Kleinanzeigen, 'https://www.kleinanzeigen.de/s-pinguino/k0', null],
            'location without radius' => [Marketplace::Kleinanzeigen, 'https://www.kleinanzeigen.de/s-55116/pinguino/k0l5315', null],
            'ebay never has one' => [Marketplace::Ebay, 'https://www.ebay.de/sch/i.html?_nkw=x&_sadis=50', null],
        ];
    }

    #[DataProvider('radii')]
    public function test_the_search_radius_is_read_from_the_url(Marketplace $marketplace, string $url, ?int $expected): void
    {
        self::assertSame($expected, $marketplace->searchRadiusKm($url));
    }
}
