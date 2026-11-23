<?php

declare(strict_types=1);

namespace App\Filament\Resources\SearchWatchResource\Pages;

use App\Enums\Marketplace;
use App\Filament\Resources\SearchWatchResource;
use App\Models\SearchWatch;
use App\Services\SearchWatchRunner;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

/**
 * @property SearchWatch $record
 */
class EditSearchWatch extends EditRecord
{
    protected static string $resource = SearchWatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('check_now')
                ->label(__('Check now'))
                ->icon('heroicon-o-arrow-path')
                ->action(function (): void {
                    $result = SearchWatchRunner::new()->check($this->record);

                    $notification = match (true) {
                        $result->ok && $result->baseline => Notification::make()->success()
                            ->title(__('Saved :count current listings as the baseline. Only listings added after this are notified.', ['count' => $result->listingsSeen])),
                        $result->ok => Notification::make()->success()
                            ->title(__(':count listings checked, :events new or cheaper.', ['count' => $result->listingsSeen, 'events' => \count($result->events)])),
                        default => Notification::make()->danger()->title((string) $result->error),
                    };
                    $notification->send();
                    $this->refreshFormData(['last_checked_at', 'last_error', 'blocked_until']);
                }),
            Actions\Action::make('open_search')
                ->label(__('Open search'))
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->url(fn (): string => $this->record->search_url, true),
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['search_url'] = \is_string($data['search_url'] ?? null) ? \trim($data['search_url']) : '';
        $data['marketplace'] = Marketplace::fromSearchUrl($data['search_url']);

        return $data;
    }
}
