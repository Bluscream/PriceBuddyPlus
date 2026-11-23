@php
    use Illuminate\Support\Number;

    $matching = collect($rows)->whereNull('mismatch')->count();
@endphp
<div class="space-y-4">
    @if ($error !== null)
        <div class="rounded-lg bg-danger-50 dark:bg-danger-400/10 p-4 text-sm text-danger-700 dark:text-danger-400">
            {{ $error }}
        </div>
    @elseif ($rows === [])
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('The search returned no listings.') }}</p>
    @else
        <p class="text-sm text-gray-600 dark:text-gray-300">
            {{ __(':matching of :total listings on the first results page match.', ['matching' => $matching, 'total' => count($rows)]) }}
            @if ($maxDistanceKm !== null)
                {{ __('Distance limit: :km km.', ['km' => $maxDistanceKm]) }}
            @endif
        </p>

        <ul class="divide-y divide-gray-200 dark:divide-white/10">
            @foreach ($rows as $row)
                @php($listing = $row['listing'])
                <li @class(['flex items-center gap-3 py-2', 'opacity-50' => $row['mismatch'] !== null])>
                    @if ($listing->image)
                        <img src="{{ $listing->image }}" alt="" loading="lazy" referrerpolicy="no-referrer"
                             class="h-12 w-16 flex-none rounded object-cover bg-gray-100 dark:bg-gray-800">
                    @else
                        <div class="h-12 w-16 flex-none rounded bg-gray-100 dark:bg-gray-800"></div>
                    @endif

                    <div class="min-w-0 flex-1">
                        <a href="{{ $listing->url }}" target="_blank" rel="noopener noreferrer"
                           class="block truncate text-sm font-medium text-gray-950 hover:underline dark:text-white">{{ $listing->title }}</a>
                        <div class="truncate text-xs text-gray-500 dark:text-gray-400">
                            {{ $listing->location ?? '' }}@if ($listing->distanceKm !== null) · {{ $listing->distanceKm }} km @endif
                        </div>
                    </div>

                    <div class="flex-none text-right">
                        <div class="text-sm font-semibold text-gray-950 dark:text-white">
                            {{ $listing->price !== null ? Number::currency($listing->price, 'EUR', 'de') : ($listing->priceText ?? '–') }}
                        </div>
                        @if ($listing->negotiable)
                            <div class="text-xs text-gray-500 dark:text-gray-400">VB</div>
                        @endif
                    </div>

                    <div class="w-48 flex-none text-xs">
                        @if ($row['mismatch'] === null)
                            <x-filament::badge color="success" icon="heroicon-m-check">{{ __('Match') }}</x-filament::badge>
                        @else
                            <span class="text-gray-500 dark:text-gray-400">{{ $row['mismatch'] }}</span>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
