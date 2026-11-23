<?php

declare(strict_types=1);

$germanLocale = ['locale_settings' => ['locale' => 'de', 'currency' => 'EUR'], 'scraper_service' => 'http', 'scraper_service_settings' => ''];

return [
    [
        'name' => 'Kleinanzeigen',
        'slug' => 'kleinanzeigen',
        'domains' => [
            ['domain' => 'kleinanzeigen.de'],
            ['domain' => 'www.kleinanzeigen.de'],
        ],
        'scrape_strategy' => [
            'title' => [
                'value' => '#viewad-title',
                'type' => 'selector',
            ],
            'price' => [
                'value' => '#viewad-price',
                'type' => 'selector',
            ],
            'image' => [
                'value' => 'meta[property=og:image]|content',
                'type' => 'selector',
            ],
        ],
        'settings' => $germanLocale,
    ],
    [
        'name' => 'eBay DE',
        'slug' => 'ebay-de',
        'domains' => [
            ['domain' => 'ebay.de'],
            ['domain' => 'www.ebay.de'],
        ],
        'scrape_strategy' => [
            'title' => [
                'value' => 'meta[property=og:title]|content',
                'type' => 'selector',
            ],
            'price' => [
                'value' => '.x-price-primary',
                'type' => 'selector',
            ],
            'image' => [
                'value' => 'meta[property=og:image]|content',
                'type' => 'selector',
            ],
        ],
        'settings' => $germanLocale,
    ],
];
