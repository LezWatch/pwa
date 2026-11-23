<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Privacy rule: the public app sets no cookies at all. No session, no CSRF
 * token, nothing a browser would keep.
 */
class PublicCookiesTest extends TestCase
{
    use RefreshDatabase;

    public static function publicUrls(): array
    {
        return [
            'home' => ['/'],
            'api' => ['/api/shows'],
            'health' => ['/up'],
        ];
    }

    #[DataProvider('publicUrls')]
    public function test_public_page_sets_no_cookies(string $url): void
    {
        $response = $this->get($url)->assertOk();

        $this->assertSame([], array_map(
            fn ($c) => $c->getName(),
            $response->headers->getCookies(),
        ));
    }

    #[DataProvider('publicUrls')]
    public function test_public_page_sets_no_cookies_after_an_admin_request(string $url): void
    {
        $this->get('/secret-door/login')->assertOk();

        $this->assertSame([], array_map(
            fn ($c) => $c->getName(),
            $this->get($url)->headers->getCookies(),
        ));
    }

    public function test_home_works_without_an_app_key(): void
    {
        config(['app.key' => '']);
        $this->app->forgetInstance('encrypter');

        $this->get('/')->assertOk();
    }
}
