<?php

namespace Tests\Unit;

use App\Catalogue\CatalogueSource;
use App\Catalogue\CatalogueUnavailable;
use App\Catalogue\LwtvListShowsSource;
use App\Catalogue\ShowRecord;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LwtvListShowsSourceTest extends TestCase
{
    private const URL = 'https://lwtv.local/wp-json/lwtv/v1/list/shows';

    protected function setUp(): void
    {
        parent::setUp();
        config(['lwtv.api_url' => 'https://lwtv.local']);
        Http::preventStrayRequests();
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

    public function test_it_throws_when_upstream_cannot_be_reached(): void
    {
        Http::fake([self::URL => fn () => throw new ConnectionException('timed out')]);

        $this->expectException(CatalogueUnavailable::class);
        (new LwtvListShowsSource)->shows();
    }
}
