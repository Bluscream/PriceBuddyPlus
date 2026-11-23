<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\MarketplaceException;
use App\Models\SearchWatchListing;
use App\Models\Store;
use App\Models\Url;
use Database\Seeders\StoreSeeder;
use Illuminate\Support\Uri;

/**
 * Turns a listing found by a search watch into a normal PriceBuddy product, so the seller's
 * price changes on that one ad get a history and alerts like any shop product.
 */
final class SearchWatchListingTracker
{
    public function track(SearchWatchListing $listing): Url
    {
        if ($listing->trackedUrl !== null) {
            return $listing->trackedUrl;
        }

        $watch = $listing->searchWatch;
        $this->ensureStore($listing->url, $watch->user_id);

        $url = Url::createFromUrl(url: $listing->url, userId: $watch->user_id, createStore: false);
        if ($url === false) {
            throw new MarketplaceException(__('The listing page could not be read. It may have been removed or the marketplace is blocking requests.'));
        }

        // A seller lowering a negotiable price is exactly what is worth hearing about.
        $url->product?->forceFill(['notify_on_change' => true])->save();
        $listing->forceFill(['url_id' => $url->getKey()])->save();

        return $url;
    }

    /**
     * Create the bundled store definition for the listing's marketplace if no store covers its
     * domain yet, instead of letting auto-detection guess at a classifieds page.
     */
    private function ensureStore(string $listingUrl, int $userId): void
    {
        $host = Uri::of($listingUrl)->host();
        if (Store::query()->domainFilter($host)->exists()) {
            return;
        }

        foreach (StoreSeeder::getStoreData('germany') as $storeData) {
            if (! \is_array($storeData) || ! \is_array($storeData['domains'] ?? null)) {
                continue;
            }

            if (\in_array($host, \array_column($storeData['domains'], 'domain'), true)) {
                $attributes = ['user_id' => $userId];
                foreach ($storeData as $key => $value) {
                    if (\is_string($key)) {
                        $attributes[$key] = $value;
                    }
                }
                Store::query()->create($attributes);

                return;
            }
        }
    }
}
