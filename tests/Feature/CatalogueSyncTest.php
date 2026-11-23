<?php

namespace Tests\Feature;

use App\Catalogue\CatalogueSync;
use App\Catalogue\CatalogueUnavailable;
use App\Catalogue\SyncAlreadyRunning;
use App\Models\Show;
use App\Models\SyncRun;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mockery;
use PDOException;
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

    private function busyDatabaseLock(string $failOn, string $error = 'SQLSTATE[HY000]: General error: 5 database is locked'): void
    {
        $busy = new QueryException('sqlite', 'update "cache_locks" ...', [], new PDOException($error));

        $lock = Mockery::mock(Lock::class);
        if ($failOn === 'get') {
            $lock->shouldReceive('get')->andThrow($busy);
        } else {
            $lock->shouldReceive('get')->andReturn(true);
            $lock->shouldReceive('release')->andThrow($busy);
        }

        $store = Mockery::mock(Repository::class);
        $store->shouldReceive('lock')->with(CatalogueSync::LOCK_NAME, CatalogueSync::LOCK_SECONDS)->andReturn($lock);
        Cache::shouldReceive('store')->with(CatalogueSync::LOCK_STORE)->andReturn($store);
    }

    public function test_busy_database_while_locking_counts_as_already_running(): void
    {
        $this->busyDatabaseLock('get');
        $this->fakeSource([$this->record(1, 'Alpha')]);

        $this->expectException(SyncAlreadyRunning::class);

        try {
            $this->sync()->run('admin');
        } finally {
            $this->assertSame(0, SyncRun::count());
        }
    }

    public function test_busy_database_while_unlocking_keeps_the_result(): void
    {
        Log::spy();
        $this->busyDatabaseLock('release');
        $this->fakeSource([$this->record(1, 'Alpha')]);

        $run = $this->sync()->run('admin');

        $this->assertSame(SyncRun::STATUS_SUCCEEDED, $run->status);
        $this->assertSame(1, Show::count());
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'lock'))->once();
    }

    public function test_cli_reports_busy_database_as_skipped(): void
    {
        $this->busyDatabaseLock('get');
        $this->fakeSource([$this->record(1, 'Alpha')]);

        $this->artisan('lwtv:sync')
            ->expectsOutput('Catalogue sync skipped: another sync is already running.')
            ->assertExitCode(1);
    }

    public function test_other_database_errors_while_locking_are_not_hidden(): void
    {
        $this->busyDatabaseLock('get', 'SQLSTATE[HY000]: General error: 1 no such table: cache_locks');
        $this->fakeSource([$this->record(1, 'Alpha')]);

        $this->expectException(QueryException::class);
        $this->sync()->run('admin');
    }
}
