<?php

declare(strict_types=1);

namespace Tests\Traits;

use Carbon\CarbonInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Testing\PendingCommand;

trait SearchWatchAssertions
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    protected function command(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);
        self::assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }

    protected static function assertSameMoment(CarbonInterface $expected, ?CarbonInterface $actual): void
    {
        self::assertNotNull($actual, 'Expected a timestamp, got null.');
        self::assertSame($expected->toIso8601String(), $actual->toIso8601String());
    }

    protected static function assertNotMoment(CarbonInterface $unexpected, ?CarbonInterface $actual): void
    {
        self::assertNotSame($unexpected->toIso8601String(), $actual?->toIso8601String());
    }

    /**
     * Narrow the untyped request Http::assertSent() hands to its callback.
     */
    protected static function request(mixed $request): Request
    {
        self::assertInstanceOf(Request::class, $request);

        return $request;
    }
}
