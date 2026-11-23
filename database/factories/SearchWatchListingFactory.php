<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SearchWatch;
use App\Models\SearchWatchListing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SearchWatchListing>
 */
class SearchWatchListingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $id = (string) $this->faker->unique()->numberBetween(1_000_000_000, 3_999_999_999);
        $price = (float) $this->faker->numberBetween(50, 500);

        return [
            'search_watch_id' => SearchWatch::factory(),
            'external_id' => $id,
            'title' => $this->faker->words(4, true),
            'url' => 'https://www.kleinanzeigen.de/s-anzeige/example/'.$id.'-176-1000',
            'price' => $price,
            'first_price' => $price,
            'lowest_price' => $price,
            'price_text' => $price.' €',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
