<?php

declare(strict_types=1);

namespace App\Services\Marketplaces;

/**
 * Reads a German classifieds price label such as "1.234 € VB", "220 €", "VB" or "Zu verschenken".
 */
final readonly class PriceText
{
    private function __construct(
        public ?float $amount,
        public bool $negotiable,
    ) {}

    public static function parse(string $text): self
    {
        $normalized = \trim(\preg_replace('/\s+/u', ' ', $text) ?? '');
        $negotiable = \preg_match('/\bVB\b/u', $normalized) === 1;

        if (\mb_stripos($normalized, 'zu verschenken') !== false) {
            return new self(0.0, false);
        }

        if (\preg_match('/(\d{1,3}(?:\.\d{3})+|\d+)(?:,(\d{1,2}))?\s*€/u', $normalized, $match) !== 1) {
            return new self(null, $negotiable);
        }

        $whole = \str_replace('.', '', $match[1]);
        $cents = isset($match[2]) ? \str_pad($match[2], 2, '0') : '00';

        return new self((float) ($whole.'.'.$cents), $negotiable);
    }
}
