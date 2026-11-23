<?php

namespace Tests\Feature;

use App\Catalogue\CatalogueSync;
use App\Catalogue\CatalogueUnavailable;
use App\Catalogue\SyncAlreadyRunning;
use App\Models\Show;
use App\Models\SyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\Concerns\FakesCatalogueSource;
use Tests\TestCase;

class CatalogueSyncTest extends TestCase
{
    use FakesCatalogueSource, RefreshDatabase;

    private function sync(): CatalogueSync
    {
        return $this->app->make(CatalogueSync::class);
    }

    public function test_success_records_counts(): void
    {
        Show::create(['id' => 1, 'title' => 'Old', 'slug' => 'old', 'url' => 'https://lwtv.local/show/old/', 'on_air' => false]);
        Show::create(['id' => 9, 'title' => 'Gone', 'slug' => 'gone', 'url' => 'https://lwtv.local/show/gone/', 'on_air' => false]);
        $this->fakeSource([$this->record(1, 'Alpha'), $this->record(2, 'Beta')]);

        $run = $this->sync()->run('admin');

        $this->assertSame('admin', $run->trigger);
        $this->assertSame(SyncRun::STATUS_SUCCEEDED, $run->status);
        $this->assertSame([2, 1, 1, 1], [$run->total, $run->added, $run->updated, $run->removed]);
        $this->assertNotNull($run->finished_at);
        $this->assertNull($run->error);
        $this->assertSame(1, SyncRun::count());
    }

    public function test_unavailable_records_failure_and_keeps_shows(): void
    {
        Log::spy();
        Show::create(['id' => 1, 'title' => 'Old', 'slug' => 'old', 'url' => 'https://lwtv.local/show/old/', 'on_air' => false]);
        $this->fakeSource(new CatalogueUnavailable('https://lwtv.local returned HTTP 500.'));

        $run = $this->sync()->run('schedule');

        $this->assertSame(SyncRun::STATUS_FAILED, $run->status);
        $this->assertSame('https://lwtv.local returned HTTP 500.', $run->error);
        $this->assertNotNull($run->finished_at);
        $this->assertNull($run->total);
        $this->assertSame('Old', Show::find(1)->title);
        Log::shouldHaveReceived('error')
            ->with('Catalogue sync failed', ['reason' => 'https://lwtv.local returned HTTP 500.'])
            ->once();
    }

    public function test_unexpected_exception_is_recorded_then_rethrown(): void
    {
        $this->fakeSource(new RuntimeException('disk full'));

        try {
            $this->sync()->run('cli');
            $this->fail('Expected the exception to be rethrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('disk full', $e->getMessage());
        }

        $run = SyncRun::sole();
        $this->assertSame(SyncRun::STATUS_FAILED, $run->status);
        $this->assertSame('disk full', $run->error);
    }

    public function test_held_lock_throws_and_writes_nothing(): void
    {
        $lock = Cache::store('database')->lock('lwtv:sync', 900);
        $this->assertTrue($lock->get());
        $this->fakeSource([$this->record(1, 'Alpha')]);

        try {
            $this->sync()->run('admin');
            $this->fail('Expected SyncAlreadyRunning.');
        } catch (SyncAlreadyRunning) {
            // expected
        } finally {
            $lock->release();
        }

        $this->assertSame(0, SyncRun::count());
        $this->assertSame(0, Show::count());
    }

    public function test_lock_is_released_after_failure(): void
    {
        $this->fakeSource(new CatalogueUnavailable('down'));
        $this->sync()->run('cli');

        $this->fakeSource(new RuntimeException('boom'));
        try {
            $this->sync()->run('cli');
        } catch (RuntimeException) {
        }

        $this->assertTrue(Cache::store('database')->lock('lwtv:sync', 900)->get());
    }

    public function test_stalled_means_running_for_over_30_minutes(): void
    {
        $fresh = SyncRun::create(['trigger' => 'cli', 'status' => SyncRun::STATUS_RUNNING, 'started_at' => now()->subMinutes(29)]);
        $old = SyncRun::create(['trigger' => 'cli', 'status' => SyncRun::STATUS_RUNNING, 'started_at' => now()->subMinutes(31)]);
        $done = SyncRun::create(['trigger' => 'cli', 'status' => SyncRun::STATUS_FAILED, 'started_at' => now()->subDay()]);

        $this->assertFalse($fresh->isStalled());
        $this->assertTrue($old->isStalled());
        $this->assertFalse($done->isStalled());
    }
}
