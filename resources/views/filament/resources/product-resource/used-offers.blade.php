@php
    /** @var App\Models\Product $record */
    use App\Services\ProductUsedOffers;

    $usedOffers = new ProductUsedOffers($record);
    $offers = $usedOffers->hasLinkedWatches() ? $usedOffers->offers() : null;
@endphp
@if ($offers !== null)
    <div class="mt-6 md:mt-8">
        <h3 class="mb-3 flex items-center gap-2 text-base font-semibold text-gray-950 dark:text-white">
            <x-filament::icon icon="heroicon-s-magnifying-glass-circle" class="h-5 w-5 text-gray-400 dark:text-gray-600" />
            {{ __('Used offers') }}
            <span class="text-xs font-normal text-gray-500 dark:text-gray-400">{{ __('from linked search watches, cheapest first') }}</span>
        </h3>
        @if ($offers === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No listing currently matches the linked search watches.') }}</p>
        @else
            <div class="grid gap-4 xl:grid-cols-2">
                @foreach ($offers as $offer)
                    @include('filament.widgets.dashboard.listing-card', [
                        'listing' => $offer['listing'],
                        'keyPrefix' => 'used',
                        'badge' => $offer['percentBelowNew'] !== null ? __('−:percent % vs. new', ['percent' => $offer['percentBelowNew']]) : null,
                    ])
                @endforeach
            </div>
        @endif
    </div>
@endif
