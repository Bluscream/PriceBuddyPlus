@php
    use App\Filament\Resources\SearchWatchResource;
    use Illuminate\Support\Number;
@endphp
@if ($watches->isNotEmpty())
    <div class="mb-8" wire:key="section-search_watches">
        <h3 class="fi-header-heading mb-4 flex gap-2 items-center text-xl md:text-2xl font-bold tracking-tight text-gray-950 dark:text-white">
            <x-filament::icon icon="heroicon-s-magnifying-glass-circle" class="h-5 w-5 text-gray-400 dark:text-gray-600" />
            {{ __('Marketplace finds') }}
            <a href="{{ SearchWatchResource::getUrl('index') }}"
               class="ml-auto text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">
                {{ __('All search watches') }}
            </a>
        </h3>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4 mb-6">
            @foreach ($watches as $watch)
                @php
                    [$statusColor, $statusText] = match (true) {
                        ! $watch->enabled => ['bg-gray-400', __('Disabled')],
                        $watch->isPaused() => ['bg-warning-500', __('Blocked, retrying :time', ['time' => $watch->blocked_until?->diffForHumans()])],
                        $watch->last_error !== null => ['bg-danger-500', __('Last check failed')],
                        $watch->last_checked_at === null => ['bg-gray-400', __('Not checked yet')],
                        default => ['bg-success-500', __('Checked :time', ['time' => $watch->last_checked_at->diffForHumans()])],
                    };
                @endphp
                <a href="{{ SearchWatchResource::getUrl('edit', ['record' => $watch]) }}"
                   wire:key="watch-{{ $watch->id }}"
                   title="{{ $watch->last_error ?? '' }}"
                   @class([
                       'flex items-center gap-3 rounded-xl bg-white p-3 shadow-sm ring-1 ring-gray-950/5 hover:ring-primary-500 dark:bg-gray-900 dark:ring-white/10',
                       'opacity-60' => ! $watch->enabled,
                   ])>
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-sm font-semibold text-gray-950 dark:text-white">{{ $watch->name }}</div>
                        <div class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                            <span class="inline-block h-2 w-2 flex-none rounded-full {{ $statusColor }}"></span>
                            <span class="truncate">{{ $statusText }}</span>
                        </div>
                    </div>
                    <div class="flex-none text-right">
                        <div class="text-xl font-bold leading-none text-gray-950 dark:text-white">{{ $watch->matching_listings_count ?? 0 }}</div>
                        @if (($watch->new_listings_count ?? 0) > 0)
                            <x-filament::badge color="primary" size="sm" class="mt-1">{{ __(':count new', ['count' => $watch->new_listings_count]) }}</x-filament::badge>
                        @else
                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('matching') }}</div>
                        @endif
                    </div>
                </a>
            @endforeach
        </div>

        @if ($deals !== [])
            <h4 class="mb-3 flex items-center gap-2 text-base font-semibold text-gray-950 dark:text-white">
                <x-filament::icon icon="heroicon-s-bolt" class="h-4 w-4 text-gray-400 dark:text-gray-600" />
                {{ __('Best deals') }}
                <span class="text-xs font-normal text-gray-500 dark:text-gray-400">{{ __('compared with the typical price of everything the watch matched') }}</span>
            </h4>
            <div class="grid gap-4 md:grid-cols-2 2xl:grid-cols-3 mb-6">
                @foreach ($deals as $deal)
                    @include('filament.widgets.dashboard.listing-card', [
                        'listing' => $deal['listing'],
                        'keyPrefix' => 'deal',
                        'badge' => __('−:percent % vs. :median', ['percent' => $deal['percentBelow'], 'median' => Number::currency($deal['median'], 'EUR', 'de')]),
                    ])
                @endforeach
            </div>
        @endif

        @if ($finds->isNotEmpty())
            <h4 class="mb-3 flex items-center gap-2 text-base font-semibold text-gray-950 dark:text-white">
                <x-filament::icon icon="heroicon-s-sparkles" class="h-4 w-4 text-gray-400 dark:text-gray-600" />
                {{ __('Newest matches') }}
            </h4>
            <div class="grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
                @foreach ($finds as $listing)
                    @include('filament.widgets.dashboard.listing-card', ['listing' => $listing, 'keyPrefix' => 'find', 'badge' => null])
                @endforeach
            </div>
        @else
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No listing currently matches any watch.') }}</p>
        @endif
    </div>
@endif
