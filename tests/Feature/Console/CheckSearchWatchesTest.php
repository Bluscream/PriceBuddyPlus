<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\CheckSearchWatches;
use App\Models\SearchWatch;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;
use Tests\Traits\SearchWatchAssertions;

final class CheckSearchWatchesTest extends TestCase
{
    use RefreshDatabase;
    use SearchWatchAssertions;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        Carbon::setTestNow('2026-11-23 12:00:00');
        Http::fake(['www.kleinanzeigen.de/*' => Http::response(
            (string) \file_get_contents(\dirname(__DIR__, 2).'/Fixtures/Kleinanzeigen/search-results.html')
        )]);
    }

    public function test_only_enabled_due_and_unblocked_watches_are_checked(): void
    {
        $user = User::factory()->create();
        $never = SearchWatch::factory()->for($user)->create(['name' => 'never']);
        $due = SearchWatch::factory()->for($user)->create(['name' => 'due', 'last_checked_at' => now()->subMinutes(31)]);
        $recent = SearchWatch::factory()->for($user)->create(['name' => 'recent', 'last_checked_at' => now()->subMinutes(5)]);
        $disabled = SearchWatch::factory()->for($user)->create(['name' => 'disabled', 'enabled' => false]);
        $blocked = SearchWatch::factory()->for($user)->create(['name' => 'blocked', 'blocked_until' => now()->addHour()]);

        $this->command(CheckSearchWatches::COMMAND)->assertSuccessful();

        self::assertNotNull($never->refresh()->last_checked_at);
        self::assertSameMoment(now(), $due->refresh()->last_checked_at);
        self::assertNotMoment(now(), $recent->refresh()->last_checked_at);
        self::assertNull($disabled->refresh()->last_checked_at);
        self::assertNull($blocked->refresh()->last_checked_at);
        Http::assertSentCount(2);
    }

    public function test_the_interval_never_drops_below_the_floor(): void
    {
        $watch = SearchWatch::factory()->create([
            'check_interval_minutes' => 1,
            'last_checked_at' => now()->subMinutes(SearchWatch::MIN_INTERVAL_MINUTES - 1),
        ]);

        $this->command(CheckSearchWatches::COMMAND)->assertSuccessful();

        Http::assertNothingSent();
        self::assertNotMoment(now(), $watch->refresh()->last_checked_at);
    }

    public function test_one_run_checks_at_most_the_configured_number_and_pauses_between(): void
    {
        config()->set('price_buddy.search_watches.max_per_run', 2);
        config()->set('price_buddy.search_watches.sleep_seconds_between', 7);
        SearchWatch::factory()->count(4)->create();

        $this->command(CheckSearchWatches::COMMAND)->assertSuccessful();

        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
        Sleep::assertSequence([Sleep::for(7)->seconds()]);
    }

    public function test_a_single_watch_can_be_checked_on_demand_even_when_not_due(): void
    {
        $watch = SearchWatch::factory()->create(['last_checked_at' => now()->subMinute()]);

        $this->command(CheckSearchWatches::COMMAND, ['--watch' => $watch->id])
            ->expectsOutputToContain('baseline of 5 listings saved')
            ->assertSuccessful();

        self::assertSame(5, $watch->listings()->count());
    }

    public function test_the_command_is_scheduled_every_minute(): void
    {
        $event = \collect(app(Schedule::class)->events())
            ->first(fn ($event) => \str_contains((string) $event->command, CheckSearchWatches::COMMAND));

        self::assertNotNull($event);
        self::assertSame('* * * * *', $event->expression);
        self::assertTrue($event->withoutOverlapping);
    }
}
