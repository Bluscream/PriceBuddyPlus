<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\SearchWatch;
use App\Models\SearchWatchListing;
use Illuminate\Database\Eloquent\Builder;

/**
 * Second-hand listings found by the search watches linked to a product, cheapest first, with
 * how far each is below the product's current new price.
 */
final readonly class ProductUsedOffers
{
    public const int LIMIT = 6;

    public function __construct(private Product $product) {}

    public function hasLinkedWatches(): bool
    {
        return SearchWatch::query()
            ->where('user_id', $this->product->user_id)
            ->whereHas('products', fn (Builder $p) => $p->whereKey($this->product->getKey()))
            ->exists();
    }

    /**
     * @return list<array{listing: SearchWatchListing, percentBelowNew: ?int}>
     */
    public function offers(): array
    {
        $newPrice = $this->product->current_price > 0 ? $this->product->current_price : null;

        $listings = SearchWatchListing::query()
            ->with('searchWatch')
            ->where('matches_filters', true)
            ->stillListed()
            ->whereHas('searchWatch', fn (Builder $watch) => $watch
                ->where('enabled', true)
                ->where('user_id', $this->product->user_id)
                ->whereHas('products', fn (Builder $p) => $p->whereKey($this->product->getKey())))
            // Priced offers first, cheapest first; VB-only ones after them.
            ->orderByRaw('price is null')
            ->orderBy('price')
            ->limit(self::LIMIT)
            ->get();

        $offers = [];
        foreach ($listings as $listing) {
            $offers[] = [
                'listing' => $listing,
                'percentBelowNew' => $newPrice !== null && $listing->price !== null && $listing->price < $newPrice
                    ? (int) \round(($newPrice - $listing->price) / $newPrice * 100)
                    : null,
            ];
        }

        return $offers;
    }
}
