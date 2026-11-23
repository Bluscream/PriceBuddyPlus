<?php

declare(strict_types=1);

namespace App\Filament\Resources\SearchWatchResource\Pages;

use App\Enums\Marketplace;
use App\Filament\Resources\SearchWatchResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSearchWatch extends CreateRecord
{
    protected static string $resource = SearchWatchResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();
        $data['search_url'] = \is_string($data['search_url'] ?? null) ? \trim($data['search_url']) : '';
        $data['marketplace'] = Marketplace::fromSearchUrl($data['search_url']);

        return $data;
    }
}
