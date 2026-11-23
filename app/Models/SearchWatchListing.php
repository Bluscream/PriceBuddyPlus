<?php

declare(strict_types=1);

namespace App\Models;

use App\Dto\MarketplaceListingDto;
use Carbon\CarbonInterface;
use Database\Factories\SearchWatchListingFactory;
use Illuminate\Database\Eloquent\Builder;
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
 * @property ?string $description
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

    /**
     * Listings that were on the results page at the watch's last successful check. Anything
     * older has been sold, removed, or pushed off the first page.
     *
     * @param  Builder<SearchWatchListing>  $query
     * @return Builder<SearchWatchListing>
     */
    public function scopeStillListed(Builder $query): Builder
    {
        return self::whereStillListed($query);
    }

    /**
     * The stillListed() constraint for a query typed only as a builder, such as a withCount()
     * callback.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function whereStillListed(Builder $query): Builder
    {
        // Grouped, so the OR cannot escape the relation's own join condition.
        return $query->whereHas('searchWatch', static fn (Builder $watch) => $watch->where(
            static fn (Builder $q) => $q
                ->whereNull('search_watches.last_succeeded_at')
                ->orWhereColumn('search_watches.last_succeeded_at', '<=', 'search_watch_listings.last_seen_at'),
        ));
    }

    /**
     * The opposite of stillListed(): missing from the last successful check.
     *
     * @param  Builder<SearchWatchListing>  $query
     * @return Builder<SearchWatchListing>
     */
    public function scopeGone(Builder $query): Builder
    {
        return self::whereGone($query);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function whereGone(Builder $query): Builder
    {
        return $query->whereHas('searchWatch', static fn (Builder $watch) => $watch
            ->whereColumn('search_watches.last_succeeded_at', '>', 'search_watch_listings.last_seen_at'));
    }

    /**
     * How far the price has fallen since the listing was first seen, in whole percent, or null
     * when it has not fallen.
     */
    public function dropPercent(): ?int
    {
        if ($this->price === null || $this->first_price === null || $this->first_price <= 0 || $this->price >= $this->first_price - 0.005) {
            return null;
        }

        return (int) \round(($this->first_price - $this->price) / $this->first_price * 100);
    }

    /**
     * The stored listing in the shape a check produces, so it can be re-scored against
     * changed filters without fetching the search again.
     */
    public function toDto(): MarketplaceListingDto
    {
        return new MarketplaceListingDto(
            externalId: $this->external_id,
            title: $this->title,
            url: $this->url,
            price: $this->price,
            priceText: $this->price_text,
            negotiable: $this->negotiable,
            location: $this->location,
            distanceKm: $this->distance_km,
            image: $this->image,
            description: $this->description,
        );
    }
}
