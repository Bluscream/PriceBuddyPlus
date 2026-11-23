<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Marketplace;
use App\Models\SearchWatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SearchWatch>
 */
class SearchWatchFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => $this->faker->words(2, true),
            'marketplace' => Marketplace::Kleinanzeigen,
            'search_url' => 'https://www.kleinanzeigen.de/s-55116/pinguino/k0l5315r50',
            'check_interval_minutes' => 30,
            'enabled' => true,
        ];
    }
}
