<?php

namespace Tests\Unit;

use App\Catalogue\CatalogueSource;
use App\Catalogue\CatalogueUnavailable;
use App\Catalogue\LwtvListShowsSource;
use App\Catalogue\ShowRecord;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class LwtvListShowsSourceTest extends TestCase
{
    private const URL = 'https://lwtv.local/wp-json/lwtv/v1/list/shows';

    protected function setUp(): void
    {
        parent::setUp();
        config(['lwtv.api_url' => 'https://lwtv.local']);
        Http::preventStrayRequests();
        Sleep::fake();
    }

    private function fakeFixture(): void
    {
        Http::fake([self::URL => Http::response(
            file_get_contents(base_path('tests/Fixtures/list-shows.json')),
            200,
            ['Content-Type' => 'application/json'],
        )]);
    }

    /** @return array<int, ShowRecord> */
    private function showsById(): array
    {
        $byId = [];
        foreach ((new LwtvListShowsSource)->shows() as $show) {
            $byId[$show->id] = $show;
        }

        return $byId;
    }

    public function test_it_is_the_bound_catalogue_source(): void
    {
        $this->assertInstanceOf(LwtvListShowsSource::class, app(CatalogueSource::class));
    }

    public function test_it_maps_each_entry_to_a_show_record(): void
    {
        $this->fakeFixture();

        $shows = $this->showsById();

        $this->assertCount(5, $shows);
        $this->assertContainsOnlyInstancesOf(ShowRecord::class, $shows);
        $hack = $shows[27002];
        $this->assertSame(27002, $hack->id);
        $this->assertSame('.hack//SIGN', $hack->title);
        $this->assertSame('hack-sign', $hack->slug);
        $this->assertSame('https://lwtv.local/show/hack-sign/', $hack->url);
        $this->assertFalse($hack->onAir);
    }

    public function test_it_decodes_html_entities_in_titles(): void
    {
        $this->fakeFixture();

        $shows = $this->showsById();

        $this->assertSame('A Centaur’s Life', $shows[37772]->title);
        $this->assertSame('1st & Ten', $shows[65959]->title);
    }

    public function test_it_trims_whitespace_including_non_breaking_spaces_from_titles(): void
    {
        $this->fakeFixture();

        $this->assertSame('A Good Girl’s Guide to Murder', $this->showsById()[92140]->title);
    }

    public function test_it_treats_onair_case_insensitively_and_defaults_false(): void
    {
        $this->fakeFixture();

        $shows = $this->showsById();

        $this->assertTrue($shows[65959]->onAir);   // "Yes"
        $this->assertTrue($shows[92140]->onAir);   // "yes"
        $this->assertFalse($shows[94168]->onAir);  // missing
    }

    public function test_it_takes_the_slug_from_the_last_path_segment(): void
    {
        $this->fakeFixture();

        $shows = $this->showsById();

        $this->assertSame('1st-and-ten', $shows[65959]->slug);  // no trailing slash
        $this->assertSame('a-centaurs-life', $shows[37772]->slug);
    }

    public function test_it_requests_the_configured_url(): void
    {
        $this->fakeFixture();

        (new LwtvListShowsSource)->shows();

        Http::assertSent(fn (Request $request) => $request->url() === self::URL);
    }

    public function test_it_throws_when_upstream_returns_an_error(): void
    {
        Http::fake([self::URL => Http::response('oops', 500)]);

        $this->expectException(CatalogueUnavailable::class);
        (new LwtvListShowsSource)->shows();
    }

    public function test_it_throws_when_upstream_returns_an_empty_object(): void
    {
        Http::fake([self::URL => Http::response('{}', 200)]);

        $this->expectException(CatalogueUnavailable::class);
        (new LwtvListShowsSource)->shows();
    }

    public function test_it_throws_when_upstream_returns_invalid_json(): void
    {
        Http::fake([self::URL => Http::response('<html>maintenance</html>', 200)]);

        $this->expectException(CatalogueUnavailable::class);
        (new LwtvListShowsSource)->shows();
    }

    public function test_it_throws_when_no_entry_is_usable(): void
    {
        Http::fake([self::URL => Http::response('{"1": "x", "2": {"title": "", "url": ""}}', 200)]);

        $this->expectException(CatalogueUnavailable::class);
        (new LwtvListShowsSource)->shows();
    }

    public function test_it_throws_when_the_payload_is_wrapped(): void
    {
        Http::fake([self::URL => Http::response(
            '{"shows": {"27002": {"title": ".hack//SIGN", "url": "https://lwtv.local/show/hack-sign/", "onair": "no"}}}',
            200,
        )]);

        $this->expectException(CatalogueUnavailable::class);
        (new LwtvListShowsSource)->shows();
    }

    public function test_it_skips_entries_without_a_numeric_id_title_or_url(): void
    {
        Http::fake([self::URL => Http::response(json_encode([
            '27002' => ['title' => '.hack//SIGN', 'url' => 'https://lwtv.local/show/hack-sign/', 'onair' => 'no'],
            'code' => ['title' => 'Not a show', 'url' => 'https://lwtv.local/show/x/'],
            '0' => ['title' => 'Zero', 'url' => 'https://lwtv.local/show/zero/'],
            '5' => ['title' => '', 'url' => 'https://lwtv.local/show/five/'],
            '6' => ['title' => 'No URL'],
        ]), 200)]);

        $ids = array_map(fn ($show) => $show->id, (new LwtvListShowsSource)->shows());

        $this->assertSame([27002], $ids);
    }

    public function test_it_retries_a_server_error_and_succeeds(): void
    {
        Http::fakeSequence(self::URL)
            ->push('oops', 503)
            ->push(file_get_contents(base_path('tests/Fixtures/list-shows.json')), 200, ['Content-Type' => 'application/json']);

        $this->assertCount(5, (new LwtvListShowsSource)->shows());
        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
    }

    public function test_it_retries_a_dropped_connection_and_succeeds(): void
    {
        $calls = 0;
        Http::fake([self::URL => function () use (&$calls) {
            if (++$calls === 1) {
                throw new ConnectionException('reset');
            }

            return Http::response(file_get_contents(base_path('tests/Fixtures/list-shows.json')), 200);
        }]);

        $this->assertCount(5, (new LwtvListShowsSource)->shows());
        $this->assertSame(2, $calls);
    }

    public function test_it_retries_rate_limiting(): void
    {
        Http::fakeSequence(self::URL)
            ->push('slow down', 429)
            ->push(file_get_contents(base_path('tests/Fixtures/list-shows.json')), 200);

        $this->assertCount(5, (new LwtvListShowsSource)->shows());
        Http::assertSentCount(2);
    }

    public function test_it_gives_up_after_the_configured_retries(): void
    {
        config(['lwtv.retry_delays' => [2000, 10000]]);
        Http::fake([self::URL => Http::response('oops', 502)]);

        try {
            (new LwtvListShowsSource)->shows();
            $this->fail('Expected CatalogueUnavailable.');
        } catch (CatalogueUnavailable $e) {
            $this->assertStringContainsString('HTTP 502', $e->getMessage());
        }

        Http::assertSentCount(3);
        Sleep::assertSequence([Sleep::for(2000)->milliseconds(), Sleep::for(10000)->milliseconds()]);
    }

    public function test_it_does_not_retry_a_client_error(): void
    {
        Http::fake([self::URL => Http::response('gone', 404)]);

        try {
            (new LwtvListShowsSource)->shows();
            $this->fail('Expected CatalogueUnavailable.');
        } catch (CatalogueUnavailable $e) {
            $this->assertStringContainsString('HTTP 404', $e->getMessage());
        }

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    public function test_it_throws_when_upstream_cannot_be_reached(): void
    {
        Http::fake([self::URL => fn () => throw new ConnectionException('timed out')]);

        $this->expectException(CatalogueUnavailable::class);
        (new LwtvListShowsSource)->shows();
    }
}
