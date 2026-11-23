<?php

declare(strict_types=1);

namespace App\Services\Marketplaces;

use App\Dto\MarketplaceListingDto;
use App\Exceptions\MarketplaceBlockedException;
use App\Exceptions\MarketplaceException;

interface MarketplaceSearch
{
    /**
     * Fetch the first page of a saved search, newest listings first.
     *
     * @return list<MarketplaceListingDto>
     *
     * @throws MarketplaceBlockedException when the marketplace refuses the request.
     * @throws MarketplaceException on any other failure.
     */
    public function search(string $searchUrl): array;
}
