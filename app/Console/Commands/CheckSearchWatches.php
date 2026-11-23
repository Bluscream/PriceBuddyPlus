<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SearchWatch;
use App\Services\SearchWatchRunner;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Sleep;

class CheckSearchWatches extends Command
{
    public const string COMMAND = 'buddy:check-search-watches';

    protected $signature = self::COMMAND.' {--watch= : Check only this watch ID, even if it is not due}';

    protected $description = 'Check saved marketplace searches that are due for new listings and price drops';

    public function handle(): int
    {
        $runner = SearchWatchRunner::new();
        $sleep = Config::integer('price_buddy.search_watches.sleep_seconds_between', 5);

        foreach ($this->watches()->values() as $index => $watch) {
            if ($index > 0 && $sleep > 0) {
                Sleep::for($sleep)->seconds();
            }

            $result = $runner->check($watch);
            $this->line(match (true) {
                $result->ok && $result->baseline => \sprintf('#%d %s: baseline of %d listings saved.', $watch->id, $watch->name, $result->listingsSeen),
                $result->ok => \sprintf('#%d %s: %d listings, %d notifications.', $watch->id, $watch->name, $result->listingsSeen, \count($result->events)),
                default => \sprintf('#%d %s: %s', $watch->id, $watch->name, $result->error),
            });
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, SearchWatch>
     */
    private function watches(): Collection
    {
        // Artisan::call() passes the option through untyped, so an int is as valid as "12".
        $only = $this->option('watch');
        if ($only !== null && $only !== '') {
            if (! \is_numeric($only)) {
                $this->error('--watch needs a numeric search watch ID.');

                return new Collection;
            }

            return SearchWatch::query()->whereKey((int) $only)->get();
        }

        return SearchWatch::query()
            ->eligible()
            ->orderByRaw('last_checked_at IS NOT NULL, last_checked_at')
            ->get()
            ->filter(fn (SearchWatch $watch): bool => $watch->isDue())
            ->take(Config::integer('price_buddy.search_watches.max_per_run', 5))
            ->values();
    }
}
