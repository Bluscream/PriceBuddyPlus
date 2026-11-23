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
}
