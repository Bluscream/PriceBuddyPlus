<?php

declare(strict_types=1);

namespace App\Filament\Resources\SearchWatchResource\RelationManagers;

use App\Exceptions\MarketplaceException;
use App\Filament\Resources\ProductResource;
use App\Models\SearchWatch;
use App\Models\SearchWatchListing;
use App\Services\SearchWatchListingTracker;
use Carbon\CarbonInterface;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Colors\Color;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;
use Livewire\Attributes\On;

class ListingsRelationManager extends RelationManager
{
    protected static string $relationship = 'listings';

    protected static ?string $title = 'Listings';

    /**
     * When the owner last opened this watch, captured before this visit is recorded, so the
     * listings that arrived in between can be marked new. A string because Livewire state
     * must survive a round trip.
     */
    public ?string $previousVisitAt = null;

    public function mount(): void
    {
        parent::mount();

        $watch = $this->watch();
        $this->previousVisitAt = $watch->listings_viewed_at?->toIso8601String();
        $watch->forceFill(['listings_viewed_at' => now()])->saveQuietly();
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    /**
     * Re-render after Check now or a save re-scored the stored listings.
     */
    #[On('search-watch-listings-changed')]
    public function refreshListings(): void {}

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns($this->columns())
            ->defaultSort('first_seen_at', 'desc')
            ->filters($this->filters())
            ->actions([
                Tables\Actions\Action::make('track')
                    ->label(__('Track price'))
                    ->icon('heroicon-o-chart-bar')
                    ->visible(fn (SearchWatchListing $record): bool => $record->url_id === null)
                    ->action(fn (SearchWatchListing $record) => $this->track($record)),
                Tables\Actions\DeleteAction::make()->label(__('Forget')),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make()->label(__('Forget selected')),
            ])
            ->emptyStateHeading(__('No listings with these filters'))
            ->emptyStateDescription(__('Clear the table filters to see listings that are gone or do not match the watch.'));
    }

    /**
     * @return list<Tables\Columns\Column>
     */
    private function columns(): array
    {
        return [
            Tables\Columns\ImageColumn::make('image')->label('')->height(48)->width(64)
                ->extraImgAttributes(['loading' => 'lazy', 'referrerpolicy' => 'no-referrer', 'class' => 'rounded object-cover']),
            Tables\Columns\TextColumn::make('title')
                ->searchable()
                ->wrap()
                ->weight(fn (SearchWatchListing $record): ?string => $this->isNew($record) ? 'bold' : null)
                ->icon(fn (SearchWatchListing $record): ?string => $this->isNew($record) ? 'heroicon-m-sparkles' : null)
                ->iconColor('primary')
                ->tooltip(fn (SearchWatchListing $record) => $this->isNew($record) ? __('New since your last visit') : null)
                ->description(fn (SearchWatchListing $record) => $this->isGone($record) ? __('No longer in the results') : null)
                ->url(fn (SearchWatchListing $record): string => $record->url, true),
            Tables\Columns\TextColumn::make('price')
                ->money('EUR', locale: 'de')
                ->sortable()
                ->color(fn (SearchWatchListing $record): ?string => $record->dropPercent() !== null ? 'success' : null)
                ->description(fn (SearchWatchListing $record): ?string => self::priceNote($record))
                ->placeholder(fn (SearchWatchListing $record): string => $record->price_text ?? '–'),
            Tables\Columns\TextColumn::make('drop')
                ->label(__('Drop'))
                ->state(fn (SearchWatchListing $record): ?string => $record->dropPercent() !== null ? '−'.$record->dropPercent().' %' : null)
                ->badge()
                ->color(Color::Emerald)
                ->placeholder(''),
            Tables\Columns\TextColumn::make('distance_km')
                ->label(__('Distance'))
                ->suffix(' km')
                ->sortable()
                ->description(fn (SearchWatchListing $record): ?string => $record->location)
                ->color(fn (SearchWatchListing $record): ?string => $this->isTooFar($record) ? 'danger' : null)
                ->placeholder('–'),
            Tables\Columns\IconColumn::make('matches_filters')->label(__('Match'))->boolean(),
            Tables\Columns\IconColumn::make('url_id')
                ->label(__('Tracked'))
                ->state(fn (SearchWatchListing $record): bool => $record->url_id !== null)
                ->boolean()
                ->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('first_seen_at')->label(__('First seen'))->since()->sortable()
                ->dateTimeTooltip(),
            Tables\Columns\TextColumn::make('last_seen_at')->label(__('Last seen'))->since()->sortable()
                ->dateTimeTooltip()
                ->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    /**
     * @return list<Tables\Filters\BaseFilter>
     */
    private function filters(): array
    {
        return [
            Tables\Filters\TernaryFilter::make('matches_filters')->label(__('Matches filters'))->default(true),
            Tables\Filters\TernaryFilter::make('still_listed')
                ->label(__('Still listed'))
                ->placeholder(__('All'))
                ->trueLabel(__('Still in the results'))
                ->falseLabel(__('Gone'))
                ->default(true)
                ->queries(
                    true: fn (Builder $query) => SearchWatchListing::whereStillListed($query),
                    false: fn (Builder $query) => SearchWatchListing::whereGone($query),
                ),
            Tables\Filters\TernaryFilter::make('has_price')
                ->label(__('Has a price'))
                ->placeholder(__('All'))
                ->queries(
                    true: fn (Builder $query) => $query->whereNotNull('price'),
                    false: fn (Builder $query) => $query->whereNull('price'),
                ),
            Tables\Filters\Filter::make('new_since_visit')
                ->label(__('New since my last visit'))
                ->toggle()
                ->query(fn (Builder $query) => $query->where('first_seen_at', '>', $this->previousVisit() ?? now())),
        ];
    }

    private function track(SearchWatchListing $record): void
    {
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
    }

    private static function priceNote(SearchWatchListing $record): ?string
    {
        $notes = [];
        if ($record->negotiable) {
            $notes[] = 'VB';
        }
        if ($record->dropPercent() !== null && $record->first_price !== null) {
            $notes[] = __('was :price', ['price' => Number::currency($record->first_price, 'EUR', 'de')]);
        }

        return $notes !== [] ? \implode(' · ', $notes) : null;
    }

    private function watch(): SearchWatch
    {
        $watch = $this->getOwnerRecord();
        \assert($watch instanceof SearchWatch);

        return $watch;
    }

    private function previousVisit(): ?CarbonInterface
    {
        return $this->previousVisitAt !== null ? Carbon::parse($this->previousVisitAt) : null;
    }

    private function isNew(SearchWatchListing $record): bool
    {
        $previous = $this->previousVisit();

        return $previous !== null && $record->first_seen_at->greaterThan($previous);
    }

    private function isGone(SearchWatchListing $record): bool
    {
        $lastSuccess = $this->watch()->last_succeeded_at;

        return $lastSuccess !== null && $record->last_seen_at->lessThan($lastSuccess);
    }

    private function isTooFar(SearchWatchListing $record): bool
    {
        $max = $this->watch()->effectiveMaxDistanceKm();

        return $max !== null && $record->distance_km !== null && $record->distance_km > $max;
    }
}
