<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A marketplace search could not be fetched or understood.
 */
class MarketplaceException extends RuntimeException {}
