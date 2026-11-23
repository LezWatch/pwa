<?php

namespace App\Http\Controllers\Admin;

use App\Catalogue\CatalogueSync;
use App\Catalogue\SyncAlreadyRunning;
use App\Http\Controllers\Controller;
use App\Models\SyncRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class SyncController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): View
    {
        $failedOnly = $request->boolean('failed');

        $runs = SyncRun::query()
            ->when($failedOnly, fn ($q) => $q->failedOrStalled())
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit(self::PER_PAGE)
            ->get();

        return view('admin.dashboard', [
            'runs' => $runs,
            'failedOnly' => $failedOnly,
            'lastSuccess' => SyncRun::where('status', SyncRun::STATUS_SUCCEEDED)->latest('started_at')->first(),
            'lastFailure' => SyncRun::failedOrStalled()->latest('started_at')->first(),
        ]);
    }

    public function sync(CatalogueSync $sync): RedirectResponse
    {
        // One HTTP fetch (30 s timeout) plus an upsert; give it room.
        set_time_limit(120);

        $back = redirect()->route('admin.dashboard');

        try {
            $run = $sync->run('admin');
        } catch (SyncAlreadyRunning) {
            return $back->with('admin.error', 'A sync is already running. Check back in a minute.');
        } catch (Throwable $e) {
            // Already recorded on the run; keep it in the log too.
            report($e);

            return $back->with('admin.error', 'Sync failed: '.$e->getMessage());
        }

        if ($run->status === SyncRun::STATUS_FAILED) {
            return $back->with('admin.error', 'Sync failed: '.$run->error);
        }

        return $back->with('admin.status', sprintf(
            'Synced %d shows (%d added, %d updated, %d removed).',
            $run->total, $run->added, $run->updated, $run->removed,
        ));
    }
}
