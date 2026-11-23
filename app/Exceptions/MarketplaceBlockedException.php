<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * The marketplace refused the request (HTTP 403 or 429). Callers back off instead of retrying,
 * because hammering a site that is already blocking us is how a temporary block becomes a long one.
 */
final class MarketplaceBlockedException extends MarketplaceException
{
    public function __construct(string $message, public readonly ?int $retryAfterSeconds = null)
    {
        parent::__construct($message);
    }
}
