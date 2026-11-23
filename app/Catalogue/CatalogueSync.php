<?php

namespace App\Catalogue;

use App\Models\Show;
use App\Models\SyncRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one place a catalogue sync happens, whoever starts it.
 */
class CatalogueSync
{
    /**
     * The database store, because the app and scheduler containers share
     * only the SQLite volume; a file-cache lock wouldn't be seen by both.
     */
    public const LOCK_STORE = 'database';

    public const LOCK_NAME = 'lwtv:sync';

    /** Long enough for any real sync, short enough that a crash doesn't block for long. */
    public const LOCK_SECONDS = 900;

    public function __construct(private CatalogueSource $source) {}

    /**
     * @param  'schedule'|'cli'|'admin'  $trigger
     *
     * @throws SyncAlreadyRunning when another sync holds the lock (no run is recorded)
     */
    public function run(string $trigger): SyncRun
    {
        $lock = Cache::store(self::LOCK_STORE)->lock(self::LOCK_NAME, self::LOCK_SECONDS);

        if (! $lock->get()) {
            throw new SyncAlreadyRunning;
        }

        try {
            $run = SyncRun::create([
                'trigger' => $trigger,
                'status' => SyncRun::STATUS_RUNNING,
                'started_at' => now(),
            ]);

            try {
                return $this->sync($run);
            } catch (Throwable $e) {
                $run->update([
                    'status' => SyncRun::STATUS_FAILED,
                    'finished_at' => now(),
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        } finally {
            $lock->release();
        }
    }

    private function sync(SyncRun $run): SyncRun
    {
        // Fetch first. If this fails, nothing below runs and the cache is untouched.
        try {
            $records = $this->source->shows();
        } catch (CatalogueUnavailable $e) {
            // Scheduled output is discarded, so keep logging alongside the run record.
            Log::error('Catalogue sync failed', ['reason' => $e->getMessage()]);

            $run->update([
                'status' => SyncRun::STATUS_FAILED,
                'finished_at' => now(),
                'error' => $e->getMessage(),
            ]);

            return $run;
        }

        $incomingIds = array_map(fn ($record) => $record->id, $records);

        [$added, $updated, $removed] = DB::transaction(function () use ($records, $incomingIds) {
            $existingIds = Show::query()->pluck('id')->all();
            $now = now();

            $rows = array_map(fn ($record) => [
                'id' => $record->id,
                'title' => $record->title,
                'slug' => $record->slug,
                'url' => $record->url,
                'on_air' => $record->onAir,
                'created_at' => $now,
                'updated_at' => $now,
            ], $records);

            // Chunked to stay under SQLite's bound-parameter limit.
            foreach (array_chunk($rows, 500) as $chunk) {
                Show::upsert($chunk, ['id'], ['title', 'slug', 'url', 'on_air', 'updated_at']);
            }

            $goneIds = array_values(array_diff($existingIds, $incomingIds));
            foreach (array_chunk($goneIds, 500) as $chunk) {
                Show::whereIn('id', $chunk)->delete();
            }

            $updated = count(array_intersect($incomingIds, $existingIds));

            return [count($records) - $updated, $updated, count($goneIds)];
        });

        $run->update([
            'status' => SyncRun::STATUS_SUCCEEDED,
            'finished_at' => now(),
            'total' => count($records),
            'added' => $added,
            'updated' => $updated,
            'removed' => $removed,
        ]);

        return $run;
    }
}
