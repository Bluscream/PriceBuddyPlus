<?php

declare(strict_types=1);

namespace App\Filament\Resources\SearchWatchResource\Pages\Concerns;

use App\Enums\Marketplace;
use App\Exceptions\MarketplaceException;
use App\Models\SearchWatch;
use App\Services\SearchWatchRunner;
use Filament\Actions\Action;
use Illuminate\Contracts\View\View;

/**
 * "Preview matches": runs the search once with the filters currently in the form, saved or not,
 * and shows what would match. Nothing is stored and nobody is notified.
 *
 * @property array<string, mixed>|null $data
 */
trait PreviewsSearch
{
    protected function previewAction(): Action
    {
        return Action::make('preview')
            ->label(__('Preview matches'))
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->modalHeading(__('What these filters would match right now'))
            ->modalDescription(__('Fetched live with the filters in the form, including unsaved changes. Nothing is saved and no notification is sent.'))
            ->modalWidth('5xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Close'))
            ->modalContent(fn (): View => $this->previewView());
    }

    private function previewView(): View
    {
        $watch = $this->watchFromForm();
        if ($watch === null) {
            return view('filament.search-watches.preview', ['error' => __('Enter a Kleinanzeigen or eBay.de search results URL first.'), 'rows' => []]);
        }

        if ($this->storedWatch()?->isPaused() === true) {
            return view('filament.search-watches.preview', ['error' => __('The marketplace blocked this watch recently. Try again after the pause ends.'), 'rows' => []]);
        }

        try {
            $rows = SearchWatchRunner::new()->preview($watch);
        } catch (MarketplaceException $e) {
            return view('filament.search-watches.preview', ['error' => $e->getMessage(), 'rows' => []]);
        }

        // Matches first; usort is stable, so the marketplace's own order holds within each group.
        \usort($rows, static fn (array $a, array $b): int => ($a['mismatch'] !== null) <=> ($b['mismatch'] !== null));

        return view('filament.search-watches.preview', [
            'error' => null,
            'rows' => $rows,
            'maxDistanceKm' => $watch->effectiveMaxDistanceKm(),
        ]);
    }

    /**
     * An unsaved watch carrying the form's current values, on top of the stored record when
     * editing, so the stored record is never touched.
     */
    private function watchFromForm(): ?SearchWatch
    {
        $data = $this->data ?? [];
        $url = \is_string($data['search_url'] ?? null) ? \trim($data['search_url']) : '';
        $marketplace = Marketplace::fromSearchUrl($url);
        if ($marketplace === null) {
            return null;
        }

        $watch = $this->storedWatch()?->replicate() ?? new SearchWatch;
        $watch->forceFill([
            'search_url' => $url,
            'marketplace' => $marketplace,
            'min_price' => self::number($data['min_price'] ?? null),
            'max_price' => self::number($data['max_price'] ?? null),
            'max_distance_km' => \is_numeric($data['max_distance_km'] ?? null) ? (int) $data['max_distance_km'] : null,
            'require_price' => (bool) ($data['require_price'] ?? false),
            'include_keywords' => \is_array($data['include_keywords'] ?? null) ? \array_values($data['include_keywords']) : null,
            'exclude_keywords' => \is_array($data['exclude_keywords'] ?? null) ? \array_values($data['exclude_keywords']) : null,
        ]);

        return $watch;
    }

    /**
     * The saved watch being edited, or null on the create page.
     */
    abstract protected function storedWatch(): ?SearchWatch;

    private static function number(mixed $value): ?float
    {
        return \is_numeric($value) ? (float) $value : null;
    }
}
