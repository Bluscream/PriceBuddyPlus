<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Marketplace;
use Carbon\CarbonInterface;
use Database\Factories\SearchWatchFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A saved marketplace search that is checked on a schedule for new listings and price drops.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property Marketplace $marketplace
 * @property string $search_url
 * @property ?float $min_price
 * @property ?float $max_price
 * @property ?int $max_distance_km
 * @property bool $require_price
 * @property ?list<string> $include_keywords
 * @property ?list<string> $exclude_keywords
 * @property bool $notify_new_listings
 * @property bool $notify_price_drops
 * @property int $check_interval_minutes
 * @property bool $enabled
 * @property ?CarbonInterface $last_checked_at
 * @property ?CarbonInterface $last_succeeded_at
 * @property ?CarbonInterface $listings_viewed_at
 * @property ?CarbonInterface $blocked_until
 * @property int $consecutive_failures
 * @property ?string $last_error
 * @property-read User $user
 * @property-read ?int $listings_count
 * @property-read ?int $matching_listings_count
 * @property-read ?int $new_listings_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SearchWatchListing> $listings
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Product> $products
 */
class SearchWatch extends Model
{
    /** @use HasFactory<SearchWatchFactory> */
    use HasFactory;

    public const int MIN_INTERVAL_MINUTES = 10;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'marketplace' => Marketplace::class,
            'min_price' => 'float',
            'max_price' => 'float',
            'max_distance_km' => 'integer',
            'require_price' => 'boolean',
            'include_keywords' => 'array',
            'exclude_keywords' => 'array',
            'notify_new_listings' => 'boolean',
            'notify_price_drops' => 'boolean',
            'check_interval_minutes' => 'integer',
            'enabled' => 'boolean',
            'last_checked_at' => 'datetime',
            'last_succeeded_at' => 'datetime',
            'listings_viewed_at' => 'datetime',
            'blocked_until' => 'datetime',
            'consecutive_failures' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<SearchWatchListing, $this>
     */
    public function listings(): HasMany
    {
        return $this->hasMany(SearchWatchListing::class);
    }

    /**
     * Tracked products this search is for, so its listings can be shown as used offers next to
     * their new prices.
     *
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class);
    }

    /**
     * @param  Builder<SearchWatch>  $query
     * @return Builder<SearchWatch>
     */
    public function scopeCurrentUser(Builder $query): Builder
    {
        return $query->where('user_id', auth()->id());
    }

    /**
     * Enabled watches that are not backing off after a block. Whether each one's interval has
     * passed is decided by isDue(), since the interval is per watch.
     *
     * @param  Builder<SearchWatch>  $query
     * @return Builder<SearchWatch>
     */
    public function scopeEligible(Builder $query, ?CarbonInterface $now = null): Builder
    {
        $now ??= now();

        return $query->where('enabled', true)
            ->where(fn (Builder $q) => $q->whereNull('blocked_until')->orWhere('blocked_until', '<=', $now));
    }

    public function isDue(?CarbonInterface $now = null): bool
    {
        $now ??= now();

        return $this->last_checked_at === null
            || $this->last_checked_at->copy()->addMinutes($this->intervalMinutes())->lessThanOrEqualTo($now);
    }

    /**
     * The interval, never below the floor that keeps us polite towards the marketplace.
     */
    public function intervalMinutes(): int
    {
        return \max(self::MIN_INTERVAL_MINUTES, $this->check_interval_minutes);
    }

    /**
     * The distance limit that applies: the watch's own, or else the radius in the search URL,
     * because Kleinanzeigen pads a thin result page with listings far outside that radius.
     */
    public function effectiveMaxDistanceKm(): ?int
    {
        return $this->max_distance_km ?? $this->marketplace->searchRadiusKm($this->search_url);
    }

    public function isPaused(?CarbonInterface $now = null): bool
    {
        return $this->blocked_until !== null && $this->blocked_until->greaterThan($now ?? now());
    }
}
