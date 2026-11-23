<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Marketplaces\PriceText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PriceTextTest extends TestCase
{
    /**
     * @return array<string, array{string, ?float, bool}>
     */
    public static function labels(): array
    {
        return [
            'plain' => ['220 €', 220.0, false],
            'negotiable' => ['220 € VB', 220.0, true],
            'thousands separator' => ['1.250 €', 1250.0, false],
            'decimals' => ['1.234,5 €', 1234.5, false],
            'negotiable without amount' => [' VB', null, true],
            'free' => ['Zu verschenken', 0.0, false],
            'empty' => ['', null, false],
            'extra whitespace' => ["  99 \u{00A0}€   VB ", 99.0, true],
        ];
    }

    #[DataProvider('labels')]
    public function test_a_classifieds_price_label_is_read_into_amount_and_negotiable(string $text, ?float $amount, bool $negotiable): void
    {
        $price = PriceText::parse($text);

        self::assertSame($amount, $price->amount);
        self::assertSame($negotiable, $price->negotiable);
    }

    public function test_a_struck_out_old_price_is_not_confused_with_the_current_one(): void
    {
        self::assertSame(400.0, PriceText::parse('400 € VB 650 €')->amount);
    }
}
