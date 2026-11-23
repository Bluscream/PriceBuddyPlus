<?php

declare(strict_types=1);

namespace App\Filament\Resources\SearchWatchResource\Pages;

use App\Enums\Marketplace;
use App\Filament\Resources\SearchWatchResource;
use App\Filament\Resources\SearchWatchResource\Pages\Concerns\PreviewsSearch;
use App\Models\SearchWatch;
use Filament\Resources\Pages\CreateRecord;

class CreateSearchWatch extends CreateRecord
{
    use PreviewsSearch;

    protected static string $resource = SearchWatchResource::class;

    protected function storedWatch(): ?SearchWatch
    {
        return null;
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->previewAction(),
        ];
    }

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
