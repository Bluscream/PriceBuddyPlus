<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\SearchWatchListingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A listing seen by a search watch, with the price history needed to spot drops.
 *
 * @property int $id
 * @property int $search_watch_id
 * @property string $external_id
 * @property string $title
 * @property string $url
 * @property ?string $image
 * @property ?string $location
 * @property ?int $distance_km
 * @property ?float $price
 * @property ?float $first_price
 * @property ?float $lowest_price
 * @property ?string $price_text
 * @property bool $negotiable
 * @property bool $matches_filters
 * @property ?int $url_id
 * @property CarbonInterface $first_seen_at
 * @property CarbonInterface $last_seen_at
 * @property-read SearchWatch $searchWatch
 * @property-read ?Url $trackedUrl
 */
class SearchWatchListing extends Model
{
    /** @use HasFactory<SearchWatchListingFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'distance_km' => 'integer',
            'price' => 'float',
            'first_price' => 'float',
            'lowest_price' => 'float',
            'negotiable' => 'boolean',
            'matches_filters' => 'boolean',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SearchWatch, $this>
     */
    public function searchWatch(): BelongsTo
    {
        return $this->belongsTo(SearchWatch::class);
    }

    /**
     * The PriceBuddy URL created when the listing was added for price tracking.
     *
     * @return BelongsTo<Url, $this>
     */
    public function trackedUrl(): BelongsTo
    {
        return $this->belongsTo(Url::class, 'url_id');
    }
}
