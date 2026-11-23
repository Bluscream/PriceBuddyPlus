<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enums\SearchWatchEventKind;
use App\Models\SearchWatchListing;

final readonly class SearchWatchEventDto
{
    public function __construct(
        public SearchWatchEventKind $kind,
        public SearchWatchListing $listing,
        public ?float $oldPrice = null,
    ) {}
}
