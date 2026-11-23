<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * One listing as it appears on a marketplace search results page.
 */
final readonly class MarketplaceListingDto
{
    public function __construct(
        public string $externalId,
        public string $title,
        public string $url,
        public ?float $price = null,
        public ?string $priceText = null,
        public bool $negotiable = false,
        public ?string $location = null,
        public ?int $distanceKm = null,
        public ?string $image = null,
        public ?string $description = null,
    ) {}
}
