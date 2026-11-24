<?php

namespace App\Catalogue;

use RuntimeException;

/**
 * The catalogue couldn't be fetched, or came back empty or malformed.
 *
 * Callers must treat this as "keep what you have": a failed fetch must never
 * empty the local catalogue.
 */
class CatalogueUnavailable extends RuntimeException {}
