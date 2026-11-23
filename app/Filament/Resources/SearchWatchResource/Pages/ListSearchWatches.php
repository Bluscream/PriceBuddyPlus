<?php

declare(strict_types=1);

namespace App\Filament\Resources\SearchWatchResource\Pages;

use App\Filament\Resources\SearchWatchResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSearchWatches extends ListRecords
{
    protected static string $resource = SearchWatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
