<?php

namespace App\Catalogue;

use App\Models\Show;
use App\Models\SyncRun;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\QueryException;
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

        try {
            $acquired = $lock->get();
        } catch (QueryException $e) {
            if (! self::isBusy($e)) {
                throw $e;
            }

            // SQLite stayed busy past its timeout: something else is writing,
            // most likely another sync's transaction. Same answer: not now.
            throw new SyncAlreadyRunning($e);
        }

        if (! $acquired) {
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
            $this->release($lock);
        }
    }

    /**
     * The sync's own result matters more than the unlock. If the database is
     * too busy to release, the lock expires on its own after LOCK_SECONDS.
     */
    private function release(Lock $lock): void
    {
        try {
            $lock->release();
        } catch (QueryException $e) {
            Log::warning('Catalogue sync lock not released; it will expire on its own', [
                'reason' => $e->getMessage(),
                'expires_in_seconds' => self::LOCK_SECONDS,
            ]);
        }
    }

    /** SQLITE_BUSY (5) or SQLITE_LOCKED (6), as PDO reports them. */
    private static function isBusy(QueryException $e): bool
    {
        return in_array($e->errorInfo[1] ?? null, [5, 6], true)
            || str_contains($e->getMessage(), 'database is locked')
            || str_contains($e->getMessage(), 'database table is locked');
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
