<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * A second-hand / classifieds marketplace whose saved searches can be watched.
 */
enum Marketplace: string implements HasDescription, HasLabel
{
    case Kleinanzeigen = 'kleinanzeigen';
    case Ebay = 'ebay';

    public function getLabel(): string
    {
        return match ($this) {
            self::Kleinanzeigen => 'Kleinanzeigen',
            self::Ebay => 'eBay.de',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Kleinanzeigen => 'A search results page on kleinanzeigen.de, e.g. https://www.kleinanzeigen.de/s-55116/pinguino/k0l5315r50',
            self::Ebay => 'A search results page on ebay.de, e.g. https://www.ebay.de/sch/i.html?_nkw=pinguino (needs eBay API keys)',
        };
    }

    public function baseUrl(): string
    {
        return match ($this) {
            self::Kleinanzeigen => 'https://www.kleinanzeigen.de',
            self::Ebay => 'https://www.ebay.de',
        };
    }

    /**
     * Work out which marketplace a search URL belongs to. Only search result pages are
     * accepted, never a single listing and never another host, because the URL is fetched
     * by the server.
     */
    public static function fromSearchUrl(string $url): ?self
    {
        $parts = \parse_url(\trim($url));
        if (! \is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
            return null;
        }

        $host = \strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';

        return match (true) {
            $host === 'www.kleinanzeigen.de'
                && \str_starts_with($path, '/s-')
                && ! \str_starts_with($path, '/s-anzeige/') => self::Kleinanzeigen,
            \in_array($host, ['www.ebay.de', 'ebay.de'], true)
                && \str_starts_with($path, '/sch/') => self::Ebay,
            default => null,
        };
    }
}
