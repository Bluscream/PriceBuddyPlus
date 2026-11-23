<?php

declare(strict_types=1);

namespace App\Filament\Resources\SearchWatchResource\RelationManagers;

use App\Exceptions\MarketplaceException;
use App\Filament\Resources\ProductResource;
use App\Models\SearchWatchListing;
use App\Services\SearchWatchListingTracker;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ListingsRelationManager extends RelationManager
{
    protected static string $relationship = 'listings';

    protected static ?string $title = 'Listings';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                Tables\Columns\ImageColumn::make('image')->label('')->height(48)->width(64),
                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->wrap()
                    ->url(fn (SearchWatchListing $record): string => $record->url, true),
                Tables\Columns\TextColumn::make('price')
                    ->money('EUR', locale: 'de')
                    ->sortable()
                    ->description(fn (SearchWatchListing $record): ?string => $record->negotiable ? 'VB' : null)
                    ->placeholder(fn (SearchWatchListing $record): string => $record->price_text ?? '–'),
                Tables\Columns\TextColumn::make('first_price')
                    ->label(__('First seen at'))
                    ->money('EUR', locale: 'de')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('location')
                    ->description(fn (SearchWatchListing $record): ?string => $record->distance_km !== null ? $record->distance_km.' km' : null)
                    ->toggleable(),
                Tables\Columns\IconColumn::make('matches_filters')->label(__('Match'))->boolean(),
                Tables\Columns\IconColumn::make('url_id')
                    ->label(__('Tracked'))
                    ->state(fn (SearchWatchListing $record): bool => $record->url_id !== null)
                    ->boolean(),
                Tables\Columns\TextColumn::make('first_seen_at')->label(__('First seen'))->since()->sortable(),
                Tables\Columns\TextColumn::make('last_seen_at')->label(__('Last seen'))->since()->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('first_seen_at', 'desc')
            ->filters([
                Tables\Filters\TernaryFilter::make('matches_filters')->label(__('Matches filters'))->default(true),
            ])
            ->actions([
                Tables\Actions\Action::make('track')
                    ->label(__('Track price'))
                    ->icon('heroicon-o-chart-bar')
                    ->visible(fn (SearchWatchListing $record): bool => $record->url_id === null)
                    ->action(function (SearchWatchListing $record): void {
                        try {
                            $url = (new SearchWatchListingTracker)->track($record);
                        } catch (MarketplaceException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()
                            ->title(__('Now tracking this listing as a product.'))
                            ->actions([
                                \Filament\Notifications\Actions\Action::make('view')
                                    ->label(__('View product'))
                                    ->url(ProductResource::getUrl('view', ['record' => $url->product_id])),
                            ])
                            ->send();
                    }),
                Tables\Actions\DeleteAction::make()->label(__('Forget')),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make()->label(__('Forget selected')),
            ]);
    }
}
