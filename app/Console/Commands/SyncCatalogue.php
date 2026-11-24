<?php

namespace App\Console\Commands;

use App\Catalogue\CatalogueSync;
use App\Catalogue\SyncAlreadyRunning;
use App\Models\SyncRun;
use Illuminate\Console\Command;

class SyncCatalogue extends Command
{
    protected $signature = 'lwtv:sync {--trigger=cli : Recorded on the sync run (cli or schedule)}';

    protected $description = 'Fetch the LezWatch.TV show list and update the local cache';

    public function handle(CatalogueSync $sync): int
    {
        $trigger = $this->option('trigger') === 'schedule' ? 'schedule' : 'cli';

        try {
            $run = $sync->run($trigger);
        } catch (SyncAlreadyRunning) {
            $this->error('Catalogue sync skipped: another sync is already running.');

            return self::FAILURE;
        }

        if ($run->status === SyncRun::STATUS_FAILED) {
            $this->error("Catalogue sync failed: {$run->error}");

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Synced %d shows (%d added, %d updated, %d removed).',
            $run->total, $run->added, $run->updated, $run->removed,
        ));

        return self::SUCCESS;
    }
}
