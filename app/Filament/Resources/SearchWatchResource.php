<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\Marketplace;
use App\Filament\Resources\SearchWatchResource\Pages;
use App\Filament\Resources\SearchWatchResource\RelationManagers\ListingsRelationManager;
use App\Models\Product;
use App\Models\SearchWatch;
use App\Models\SearchWatchListing;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SearchWatchResource extends Resource
{
    protected static ?string $model = SearchWatch::class;

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationIcon = 'heroicon-o-magnifying-glass-circle';

    protected static ?string $navigationLabel = 'Search watches';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('Search'))
                ->description(__('Run a search on Kleinanzeigen or eBay.de with the location, radius and price you want, then paste the address of the results page here.'))
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('search_url')
                        ->label(__('Search results URL'))
                        ->required()
                        ->maxLength(2000)
                        // Marketplace::fromSearchUrl() trims and checks scheme, host and path,
                        // so a pasted URL with stray spaces is accepted and saved trimmed.
                        ->rules([
                            fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                if (! \is_string($value) || Marketplace::fromSearchUrl($value) === null) {
                                    $fail(__('Use a search results page from kleinanzeigen.de or ebay.de, not a single listing.'));
                                }
                            },
                        ])
                        ->helperText(__('Kleinanzeigen: e.g. https://www.kleinanzeigen.de/s-55116/pinguino/k0l5315r50. Pick a category on Kleinanzeigen before copying the URL to keep unrelated ads out. eBay: e.g. https://www.ebay.de/sch/i.html?_nkw=pinguino')),
                ]),
            Forms\Components\Section::make(__('Filters'))
                ->description(__('Applied on top of the search itself. Listings outside them are still recorded but never notified.'))
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('min_price')->numeric()->minValue(0)->prefix('€'),
                    Forms\Components\TextInput::make('max_price')->numeric()->minValue(0)->prefix('€')
                        ->rules([
                            fn (Forms\Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                $min = $get('min_price');
                                if (\is_numeric($min) && \is_numeric($value) && (float) $value < (float) $min) {
                                    $fail(__('The maximum price must not be below the minimum price.'));
                                }
                            },
                        ]),
                    Forms\Components\TextInput::make('max_distance_km')->label(__('Max distance'))->integer()->minValue(1)->suffix('km')
                        ->helperText(__('Empty uses the radius in the Kleinanzeigen URL, since it fills short result pages with ads from much further away.')),
                    Forms\Components\Toggle::make('require_price')
                        ->label(__('Require a price'))
                        ->helperText(__('Skip ads without a price, such as “VB” only or “Zu verschenken”.'))
                        ->columnSpan(['default' => 3, 'md' => 'full']),
                    Forms\Components\TagsInput::make('include_keywords')
                        ->label(__('Title must contain one of'))
                        ->placeholder(__('e.g. pinguino'))
                        ->columnSpan(['default' => 3, 'md' => 'full']),
                    Forms\Components\TagsInput::make('exclude_keywords')
                        ->label(__('Skip listings mentioning'))
                        ->placeholder(__('e.g. defekt, suche'))
                        ->columnSpan(['default' => 3, 'md' => 'full']),
                    Forms\Components\Select::make('products')
                        ->label(__('Compare with products'))
                        ->helperText(__('Matching listings show up as used offers on these products, next to their new price.'))
                        ->relationship('products', 'title', fn (Builder $query) => $query->where('products.user_id', auth()->id()))
                        ->getOptionLabelFromRecordUsing(fn (Product $product): string => $product->title ?? '#'.$product->id)
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->columnSpan(['default' => 3, 'md' => 'full']),
                ]),
            Forms\Components\Section::make(__('Checking & notifications'))
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('check_interval_minutes')
                        ->label(__('Check every'))
                        ->integer()
                        ->required()
                        ->default(30)
                        ->minValue(SearchWatch::MIN_INTERVAL_MINUTES)
                        ->suffix(__('minutes')),
                    Forms\Components\Toggle::make('enabled')->default(true)->inline(false),
                    Forms\Components\Toggle::make('notify_new_listings')->label(__('Notify about new listings'))->default(true),
                    Forms\Components\Toggle::make('notify_price_drops')->label(__('Notify about price drops'))->default(true),
                ]),
            Forms\Components\Section::make(__('Status'))
                ->visibleOn('edit')
                ->columns(4)
                ->schema([
                    Forms\Components\Placeholder::make('last_checked_at')
                        ->label(__('Last checked'))
                        ->content(fn (?SearchWatch $record): string => $record?->last_checked_at?->diffForHumans() ?? __('Never')),
                    Forms\Components\Placeholder::make('last_succeeded_at')
                        ->label(__('Last successful check'))
                        ->content(fn (?SearchWatch $record): string => $record?->last_succeeded_at?->diffForHumans() ?? __('Never')),
                    Forms\Components\Placeholder::make('blocked_until')
                        ->label(__('Paused until'))
                        ->content(fn (?SearchWatch $record): string => $record?->blocked_until?->isFuture() ? $record->blocked_until->diffForHumans() : '–'),
                    Forms\Components\Placeholder::make('last_error')
                        ->label(__('Last error'))
                        ->content(fn (?SearchWatch $record): string => $record->last_error ?? '–'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->weight(FontWeight::Bold)->searchable()->sortable(),
                Tables\Columns\TextColumn::make('marketplace')->badge(),
                Tables\Columns\TextColumn::make('matching_listings_count')
                    ->label(__('Matching'))
                    ->counts([
                        'listings as matching_listings_count' => fn (Builder $q) => SearchWatchListing::whereStillListed($q->where('matches_filters', true)),
                    ])
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'gray')
                    ->description(fn (SearchWatch $record): string => __(':count seen', ['count' => $record->listings_count ?? 0]))
                    ->sortable(),
                Tables\Columns\TextColumn::make('max_price')->label(__('Max price'))->money('EUR', locale: 'de')->placeholder('–'),
                Tables\Columns\TextColumn::make('last_checked_at')->label(__('Last checked'))->since()->sortable()->placeholder(__('Never')),
                Tables\Columns\IconColumn::make('status')
                    ->label(__('Status'))
                    ->state(fn (SearchWatch $record): string => match (true) {
                        $record->isPaused() => 'paused',
                        $record->last_error !== null => 'error',
                        default => 'ok',
                    })
                    ->icon(fn (string $state): string => match ($state) {
                        'paused' => 'heroicon-o-pause-circle',
                        'error' => 'heroicon-o-exclamation-triangle',
                        default => 'heroicon-o-check-circle',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'paused' => 'warning',
                        'error' => 'danger',
                        default => 'success',
                    })
                    ->tooltip(fn (SearchWatch $record): ?string => $record->isPaused()
                        ? __('Blocked by the marketplace, paused until :time.', ['time' => $record->blocked_until?->diffForHumans()]).' '.$record->last_error
                        : $record->last_error),
                Tables\Columns\ToggleColumn::make('enabled'),
            ])
            ->defaultSort('name')
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->where('user_id', auth()->id())->withCount('listings'));
    }

    public static function getRelations(): array
    {
        return [
            ListingsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSearchWatches::route('/'),
            'create' => Pages\CreateSearchWatch::route('/create'),
            'edit' => Pages\EditSearchWatch::route('/{record}/edit'),
        ];
    }
}
