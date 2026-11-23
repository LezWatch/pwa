<?php

namespace App\Catalogue;

use RuntimeException;
use Throwable;

class SyncAlreadyRunning extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('Another sync is already running.', previous: $previous);
    }
}
