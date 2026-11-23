<?php

namespace App\Console\Commands;

use App\Catalogue\CatalogueSource;
use App\Catalogue\CatalogueUnavailable;
use App\Models\Show;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncCatalogue extends Command
{
    protected $signature = 'lwtv:sync';

    protected $description = 'Fetch the LezWatch.TV show list and update the local cache';

    public function handle(CatalogueSource $source): int
    {
        // Fetch first. If this fails, nothing below runs and the cache is untouched.
        try {
            $records = $source->shows();
        } catch (CatalogueUnavailable $e) {
            $this->error("Catalogue sync failed: {$e->getMessage()}");

            return self::FAILURE;
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

        $this->info(sprintf(
            'Synced %d shows (%d added, %d updated, %d removed).',
            count($records), $added, $updated, $removed,
        ));

        return self::SUCCESS;
    }
}
