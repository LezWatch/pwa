<?php

namespace Tests\Feature;

use App\Catalogue\CatalogueUnavailable;
use App\Models\Show;
use App\Models\SyncRun;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\FakesCatalogueSource;
use Tests\TestCase;

class SyncCatalogueTest extends TestCase
{
    use FakesCatalogueSource, RefreshDatabase;

    private function existing(int $id, string $title): Show
    {
        return Show::create([
            'id' => $id,
            'title' => $title,
            'slug' => str($title)->slug()->toString(),
            'url' => 'https://lwtv.local/show/x/',
            'on_air' => false,
        ]);
    }

    public function test_it_inserts_new_shows(): void
    {
        $this->fakeSource([
            $this->record(1, 'Alpha', true),
            $this->record(2, 'Beta'),
            $this->record(3, 'Gamma'),
        ]);

        $this->artisan('lwtv:sync')
            ->expectsOutput('Synced 3 shows (3 added, 0 updated, 0 removed).')
            ->assertExitCode(0);

        $this->assertSame(3, Show::count());
        $alpha = Show::find(1);
        $this->assertSame('Alpha', $alpha->title);
        $this->assertSame('alpha', $alpha->slug);
        $this->assertTrue($alpha->on_air);
    }

    public function test_it_updates_changed_shows(): void
    {
        $this->existing(1, 'Old Title');
        $this->fakeSource([$this->record(1, 'New Title')]);

        $this->artisan('lwtv:sync')
            ->expectsOutput('Synced 1 shows (0 added, 1 updated, 0 removed).')
            ->assertExitCode(0);

        $this->assertSame('New Title', Show::find(1)->title);
    }

    public function test_it_removes_shows_missing_from_the_feed(): void
    {
        $this->existing(1, 'Stays');
        $this->existing(2, 'Goes');
        $this->fakeSource([$this->record(1, 'Stays')]);

        $this->artisan('lwtv:sync')
            ->expectsOutput('Synced 1 shows (0 added, 1 updated, 1 removed).')
            ->assertExitCode(0);

        $this->assertNull(Show::find(2));
        $this->assertNotNull(Show::find(1));
    }

    public function test_it_leaves_data_alone_when_the_source_fails(): void
    {
        $this->existing(1, 'One');
        $this->existing(2, 'Two');
        $this->existing(3, 'Three');
        $this->fakeSource(new CatalogueUnavailable('https://lwtv.local returned HTTP 500.'));

        $this->artisan('lwtv:sync')
            ->expectsOutput('Catalogue sync failed: https://lwtv.local returned HTTP 500.')
            ->assertExitCode(1);

        $this->assertSame(3, Show::count());
    }

    public function test_it_logs_the_reason_when_the_source_fails(): void
    {
        Log::spy();
        $this->fakeSource(new CatalogueUnavailable('https://lwtv.local returned HTTP 500.'));

        $this->artisan('lwtv:sync')->assertExitCode(1);

        Log::shouldHaveReceived('error')
            ->with('Catalogue sync failed', ['reason' => 'https://lwtv.local returned HTTP 500.'])
            ->once();
    }

    public function test_it_is_scheduled_every_six_hours(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'lwtv:sync'));

        $this->assertStringContainsString('--trigger=schedule', $events->first()?->command ?? '');

        $this->assertCount(1, $events);
        $this->assertSame('0 */6 * * *', $events->first()->expression);
        $this->assertTrue($events->first()->withoutOverlapping);
        // A killed run must not block syncing for the default 24 hours.
        $this->assertSame(30, $events->first()->expiresAt);
    }

    public function test_cli_run_is_recorded_as_cli(): void
    {
        $this->fakeSource([$this->record(1, 'Alpha')]);

        $this->artisan('lwtv:sync')->assertExitCode(0);

        $run = SyncRun::sole();
        $this->assertSame('cli', $run->trigger);
        $this->assertSame(SyncRun::STATUS_SUCCEEDED, $run->status);
    }

    public function test_it_fails_cleanly_when_a_sync_is_already_running(): void
    {
        $lock = Cache::store('database')->lock('lwtv:sync', 900);
        $lock->get();
        $this->fakeSource([$this->record(1, 'Alpha')]);

        $this->artisan('lwtv:sync')
            ->expectsOutput('Catalogue sync skipped: another sync is already running.')
            ->assertExitCode(1);

        $lock->release();
        $this->assertSame(0, Show::count());
    }
}
