<?php

namespace Tests\Feature;

use App\Models\Show;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function show(int $id, string $title, bool $onAir = false): Show
    {
        $slug = str($title)->slug()->toString();

        return Show::create([
            'id' => $id,
            'title' => $title,
            'slug' => $slug,
            'url' => "https://lwtv.local/show/{$slug}/",
            'on_air' => $onAir,
        ]);
    }

    public function test_api_returns_shows_sorted_by_title(): void
    {
        $this->show(2, 'beta');
        $this->show(1, 'Alpha', true);

        $this->getJson('/api/shows')
            ->assertOk()
            ->assertExactJson([
                'schema_version' => 1,
                'count' => 2,
                'shows' => [
                    ['id' => 1, 'title' => 'Alpha', 'slug' => 'alpha', 'url' => 'https://lwtv.local/show/alpha/', 'on_air' => true],
                    ['id' => 2, 'title' => 'beta', 'slug' => 'beta', 'url' => 'https://lwtv.local/show/beta/', 'on_air' => false],
                ],
            ]);
    }

    public function test_api_returns_empty_list_when_not_synced(): void
    {
        $this->getJson('/api/shows')
            ->assertOk()
            ->assertExactJson(['schema_version' => 1, 'count' => 0, 'shows' => []]);
    }

    public function test_api_sets_no_cookies(): void
    {
        $this->show(1, 'Alpha');

        $response = $this->getJson('/api/shows')->assertOk();

        $this->assertFalse($response->headers->has('Set-Cookie'));
    }

    public function test_home_lists_show_titles(): void
    {
        $this->show(1, 'Alpha', true);
        $this->show(2, 'Beta');

        $this->get('/')
            ->assertOk()
            ->assertSeeInOrder(['Alpha', 'On air', 'Beta'])
            ->assertSee('href="https://lwtv.local/show/alpha/"', escape: false);
    }

    public function test_home_explains_when_empty(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('No shows yet. Run php artisan lwtv:sync.');
    }
}
