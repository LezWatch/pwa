<?php

namespace App\Catalogue;

use RuntimeException;

class SyncAlreadyRunning extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Another sync is already running.');
    }
}
