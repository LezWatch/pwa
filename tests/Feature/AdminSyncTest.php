<?php

namespace Tests\Feature;

use App\Catalogue\CatalogueSync;
use App\Catalogue\CatalogueUnavailable;
use App\Models\Show;
use App\Models\SyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\ActsAsAdmin;
use Tests\Concerns\FakesCatalogueSource;
use Tests\TestCase;

class AdminSyncTest extends TestCase
{
    use ActsAsAdmin, FakesCatalogueSource, RefreshDatabase;

    private function run_(string $status, array $extra = []): SyncRun
    {
        return SyncRun::create(array_merge([
            'trigger' => 'schedule',
            'status' => $status,
            'started_at' => now()->subHour(),
            'finished_at' => $status === SyncRun::STATUS_RUNNING ? null : now()->subHour()->addSeconds(5),
        ], $extra));
    }

    public function test_dashboard_lists_runs_with_failure_reason(): void
    {
        $this->run_(SyncRun::STATUS_SUCCEEDED, ['total' => 2266, 'added' => 3, 'updated' => 2263, 'removed' => 0]);
        $this->run_(SyncRun::STATUS_FAILED, ['error' => 'https://lezwatchtv.com returned HTTP 502.']);

        $this->signedInAs()->get($this->adminUrl())
            ->assertOk()
            ->assertSee('Sync log')
            ->assertSee('https://lezwatchtv.com returned HTTP 502.')
            ->assertSee('2,266')
            ->assertSee('Failed')
            ->assertSee('Succeeded');
    }

    public function test_dashboard_shows_newest_first_and_caps_at_50(): void
    {
        for ($i = 1; $i <= 55; $i++) {
            $this->run_(SyncRun::STATUS_FAILED, ['error' => "reason-{$i}-end", 'started_at' => now()->subMinutes(100 - $i)]);
        }

        $response = $this->signedInAs()->get($this->adminUrl())->assertOk();

        $response->assertSeeInOrder(['reason-55-end', 'reason-54-end']);
        $response->assertSee('reason-6-end');
        $response->assertDontSee('reason-5-end');
    }

    public function test_failed_filter_hides_successes(): void
    {
        $this->run_(SyncRun::STATUS_SUCCEEDED, ['total' => 777777, 'added' => 0, 'updated' => 0, 'removed' => 0]);
        $this->run_(SyncRun::STATUS_FAILED, ['error' => 'timed out']);

        $this->signedInAs()->get($this->adminUrl().'?failed=1')
            ->assertOk()
            ->assertSee('timed out')
            ->assertDontSee('class="badge badge-succeeded"', false);
    }

    public function test_stalled_run_is_labelled(): void
    {
        $this->run_(SyncRun::STATUS_RUNNING, ['started_at' => now()->subHours(2)]);

        $this->signedInAs()->get($this->adminUrl())->assertOk()->assertSee('Stalled');
    }

    public function test_empty_log_says_so(): void
    {
        $this->signedInAs()->get($this->adminUrl())->assertOk()->assertSee('No syncs recorded yet.');
    }

    public function test_sync_form_carries_csrf_token(): void
    {
        $this->signedInAs()->get($this->adminUrl())
            ->assertOk()
            ->assertSee('action="'.url($this->adminUrl('sync')).'"', false)
            ->assertSee('name="_token"', false);
    }

    public function test_sync_now_runs_and_records_admin_run(): void
    {
        $this->fakeSource([$this->record(1, 'Alpha'), $this->record(2, 'Beta')]);

        $this->signedInAs()->post($this->adminUrl('sync'))
            ->assertRedirect($this->adminUrl())
            ->assertSessionHas('admin.status', 'Synced 2 shows (2 added, 0 updated, 0 removed).');

        $run = SyncRun::sole();
        $this->assertSame('admin', $run->trigger);
        $this->assertSame(SyncRun::STATUS_SUCCEEDED, $run->status);
        $this->assertSame(2, Show::count());
    }

    public function test_sync_now_reports_failure_reason(): void
    {
        $this->fakeSource(new CatalogueUnavailable('https://lezwatchtv.com returned HTTP 500.'));

        $this->signedInAs()->post($this->adminUrl('sync'))
            ->assertRedirect($this->adminUrl())
            ->assertSessionHas('admin.error', 'Sync failed: https://lezwatchtv.com returned HTTP 500.');

        $this->assertSame(SyncRun::STATUS_FAILED, SyncRun::sole()->status);
    }

    public function test_sync_now_with_held_lock_does_nothing(): void
    {
        $lock = Cache::store(CatalogueSync::LOCK_STORE)->lock(CatalogueSync::LOCK_NAME, 900);
        $this->assertTrue($lock->get());
        $this->fakeSource([$this->record(1, 'Alpha')]);

        $this->signedInAs()->post($this->adminUrl('sync'))
            ->assertRedirect($this->adminUrl())
            ->assertSessionHas('admin.error', 'A sync is already running. Check back in a minute.');

        $lock->release();
        $this->assertSame(0, SyncRun::count());
        $this->assertSame(0, Show::count());
    }

    public function test_sync_now_requires_post(): void
    {
        $this->fakeSource([$this->record(1, 'Alpha')]);

        $this->signedInAs()->get($this->adminUrl('sync'))->assertMethodNotAllowed();
        $this->assertSame(0, SyncRun::count());
    }

    public function test_sync_now_requires_admin(): void
    {
        $this->fakeSource([$this->record(1, 'Alpha')]);

        $this->post($this->adminUrl('sync'))->assertRedirect($this->adminUrl('login'));
        $this->assertSame(0, SyncRun::count());
    }

    public function test_sync_now_rechecks_membership_when_due(): void
    {
        $this->fakeGroup(false);
        $this->fakeSource([$this->record(1, 'Alpha')]);

        $this->signedInAs(checkedAt: now()->subDays(2)->getTimestamp())
            ->post($this->adminUrl('sync'))
            ->assertRedirect($this->adminUrl('login'));

        $this->assertSame(0, SyncRun::count());
    }

    public function test_stalled_run_counts_as_the_last_failure(): void
    {
        $this->run_(SyncRun::STATUS_FAILED, ['error' => 'old', 'started_at' => now()->subDays(3)]);
        $this->run_(SyncRun::STATUS_RUNNING, ['started_at' => now()->subHours(2)]);

        $this->signedInAs()->get($this->adminUrl())
            ->assertOk()
            ->assertSeeInOrder(['Last failure', '2 hours ago', '(stalled)', 'Sync now']);
    }

    public function test_a_run_in_progress_is_not_a_failure(): void
    {
        $this->run_(SyncRun::STATUS_RUNNING, ['started_at' => now()->subMinutes(5)]);

        $this->signedInAs()->get($this->adminUrl())
            ->assertOk()
            ->assertSeeInOrder(['Last failure', 'None', 'Sync now']);
    }

    public function test_failed_filter_includes_stalled_runs(): void
    {
        $this->run_(SyncRun::STATUS_RUNNING, ['started_at' => now()->subHours(2)]);
        $this->run_(SyncRun::STATUS_RUNNING, ['started_at' => now()->subMinutes(5)]);

        $this->signedInAs()->get($this->adminUrl().'?failed=1')
            ->assertOk()
            ->assertSee('class="badge badge-stalled"', false)
            ->assertDontSee('class="badge badge-running"', false);
    }
}
