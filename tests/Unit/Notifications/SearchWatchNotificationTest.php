<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Dto\SearchWatchEventDto;
use App\Enums\SearchWatchEventKind;
use App\Models\SearchWatch;
use App\Models\SearchWatchListing;
use App\Notifications\SearchWatchNotification;
use Tests\TestCase;

final class SearchWatchNotificationTest extends TestCase
{
    private static function watch(): SearchWatch
    {
        $watch = new SearchWatch(['name' => 'Pinguino']);
        $watch->id = 7;

        return $watch;
    }

    private static function listing(string $id, ?float $price, ?string $priceText = null): SearchWatchListing
    {
        return new SearchWatchListing([
            'external_id' => $id,
            'title' => 'Pinguino '.$id,
            'url' => 'https://www.kleinanzeigen.de/s-anzeige/x/'.$id,
            'price' => $price,
            'price_text' => $priceText,
            'location' => '10001 Musterstadt',
            'distance_km' => 12,
        ]);
    }

    public function test_a_single_new_listing_links_straight_to_it(): void
    {
        $notification = new SearchWatchNotification(self::watch(), [
            new SearchWatchEventDto(SearchWatchEventKind::NewListing, self::listing('1', 140.0, '140 € VB')),
        ]);

        self::assertSame('New on Pinguino: 140 € VB', $notification->getTitle());
        self::assertSame('140 € VB · Pinguino 1 · 10001 Musterstadt (12 km)', $notification->getSummary());
        self::assertSame('https://www.kleinanzeigen.de/s-anzeige/x/1', $notification->getUrl());
    }

    public function test_a_price_drop_shows_old_and_new_price(): void
    {
        $notification = new SearchWatchNotification(self::watch(), [
            new SearchWatchEventDto(SearchWatchEventKind::PriceDrop, self::listing('1', 99.5), oldPrice: 150.0),
        ]);

        self::assertSame('Price drop on Pinguino: 150 € → 99,50 €', $notification->getTitle());
    }

    public function test_many_events_are_summarised_and_truncated(): void
    {
        $events = \array_map(
            fn (int $i) => new SearchWatchEventDto(SearchWatchEventKind::NewListing, self::listing((string) $i, 100.0)),
            \range(1, 7),
        );

        $notification = new SearchWatchNotification(self::watch(), $events);

        self::assertSame('7 updates on Pinguino', $notification->getTitle());
        $lines = \explode("\n", $notification->getSummary());
        self::assertCount(6, $lines);
        self::assertSame('…and 2 more.', $lines[5]);
    }
}
