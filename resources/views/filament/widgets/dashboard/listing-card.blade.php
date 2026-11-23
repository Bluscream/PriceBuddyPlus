@php
    use Illuminate\Support\Number;

    $drop = $listing->dropPercent();
@endphp
<a href="{{ $listing->url }}" target="_blank" rel="noopener noreferrer"
   wire:key="{{ $keyPrefix }}-{{ $listing->id }}"
   class="flex gap-3 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 hover:ring-primary-500 dark:bg-gray-900 dark:ring-white/10">
    @if ($listing->image)
        <img src="{{ $listing->image }}" alt="" loading="lazy" referrerpolicy="no-referrer"
             class="h-24 w-24 flex-none object-cover bg-gray-100 dark:bg-gray-800">
    @else
        <div class="flex h-24 w-24 flex-none items-center justify-center bg-gray-100 dark:bg-gray-800">
            <x-filament::icon icon="heroicon-o-photo" class="h-8 w-8 text-gray-400" />
        </div>
    @endif

    <div class="flex min-w-0 flex-1 flex-col justify-center py-2 pr-3">
        <div class="truncate text-xs font-semibold text-gray-500 dark:text-gray-400">{{ $listing->searchWatch->name }}</div>
        <div class="truncate text-sm font-medium text-gray-950 dark:text-white" title="{{ $listing->title }}">{{ $listing->title }}</div>
        <div class="mt-1 flex flex-wrap items-baseline gap-x-2 gap-y-1">
            <span class="text-lg font-bold leading-none text-gray-950 dark:text-white">
                {{ $listing->price !== null ? Number::currency($listing->price, 'EUR', 'de') : ($listing->price_text ?? '–') }}
            </span>
            @if ($listing->negotiable)
                <span class="text-xs text-gray-500 dark:text-gray-400">VB</span>
            @endif
            @if ($drop !== null)
                <x-filament::badge color="success" size="sm">−{{ $drop }} %</x-filament::badge>
            @endif
            @if ($badge !== null)
                <x-filament::badge color="primary" size="sm">{{ $badge }}</x-filament::badge>
            @endif
        </div>
        <div class="mt-1 truncate text-xs text-gray-500 dark:text-gray-400">
            {{ $listing->location ?? '' }}@if ($listing->distance_km !== null) · {{ $listing->distance_km }} km @endif · {{ $listing->first_seen_at->diffForHumans() }}
        </div>
    </div>
</a>
