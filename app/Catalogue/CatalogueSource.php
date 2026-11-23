<?php

namespace App\Catalogue;

/**
 * Where the show catalogue comes from.
 *
 * Today: LezWatch.TV's lwtv/v1/list/shows route. Later: the compact app API
 * (LezWatch/lwtv-underscores#755), as a second implementation.
 */
interface CatalogueSource
{
    /**
     * @return list<ShowRecord>
     *
     * @throws CatalogueUnavailable when the catalogue can't be fetched or is empty
     */
    public function shows(): array;
}
