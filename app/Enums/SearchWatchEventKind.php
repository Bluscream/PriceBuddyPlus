<?php

declare(strict_types=1);

namespace App\Enums;

enum SearchWatchEventKind: string
{
    case NewListing = 'new_listing';
    case PriceDrop = 'price_drop';
}
