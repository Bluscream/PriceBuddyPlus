<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class SearchWatchCheckResultDto
{
    /**
     * @param  list<SearchWatchEventDto>  $events
     */
    public function __construct(
        public bool $ok,
        public int $listingsSeen = 0,
        public array $events = [],
        public bool $baseline = false,
        public bool $blocked = false,
        public ?string $error = null,
    ) {}
}
