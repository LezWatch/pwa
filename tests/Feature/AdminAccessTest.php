<?php

namespace Tests\Feature;

use App\Admin\GroupCheckFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use ActsAsAdmin, RefreshDatabase;

    private function googleReturns(string $email, ?string $hd, bool $verified = true): void
    {
        $user = (new SocialiteUser)
            ->setRaw(array_filter(['email' => $email, 'hd' => $hd, 'email_verified' => $verified], fn ($v) => $v !== null))
            ->map(['id' => '123', 'email' => $email, 'name' => 'Someone']);

        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($user);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    public function test_admin_is_off_when_path_empty(): void
    {
        $previous = [$_ENV['ADMIN_PATH'] ?? null, $_SERVER['ADMIN_PATH'] ?? null, getenv('ADMIN_PATH')];
        $_ENV['ADMIN_PATH'] = $_SERVER['ADMIN_PATH'] = '';
        putenv('ADMIN_PATH=');

        try {
            $this->refreshApplication();
            $this->assertSame('', config('admin.path'));
            $this->get('/secret-door')->assertNotFound();
            $this->get('/login')->assertNotFound();
            $this->get('/callback')->assertNotFound();
        } finally {
            [$_ENV['ADMIN_PATH'], $_SERVER['ADMIN_PATH']] = [$previous[0], $previous[1]];
            putenv('ADMIN_PATH='.$previous[2]);
        }
    }

    public function test_signed_out_visitor_is_sent_to_login(): void
    {
        $this->get($this->adminUrl())->assertRedirect($this->adminUrl('login'));
    }

    public function test_login_page_offers_google_sign_in(): void
    {
        $this->get($this->adminUrl('login'))
            ->assertOk()
            ->assertSee($this->adminUrl('login/google'));
    }

    public function test_google_redirect_is_limited_to_the_domain(): void
    {
        $this->get($this->adminUrl('login/google'))
            ->assertRedirectContains('accounts.google.com')
            ->assertRedirectContains('hd=lezwatchtv.com')
            ->assertRedirectContains(urlencode('/secret-door/callback'));
    }

    public function test_wrong_or_missing_domain_is_refused(): void
    {
        $this->fakeGroup(true);

        foreach (['gmail.com', null] as $hd) {
            $this->googleReturns('someone@gmail.com', $hd);

            $this->get($this->adminUrl('callback'))
                ->assertForbidden()
                ->assertSessionMissing('admin.email');
        }

        $this->assertSame([], $this->groupChecks);
    }

    public function test_unverified_email_is_refused(): void
    {
        $this->fakeGroup(true);
        $this->googleReturns('mika@lezwatchtv.com', 'lezwatchtv.com', verified: false);

        $this->get($this->adminUrl('callback'))->assertForbidden()->assertSessionMissing('admin.email');
    }

    public function test_bad_oauth_state_is_refused(): void
    {
        $this->fakeGroup(true);
        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('user')->andThrow(new InvalidStateException);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->get($this->adminUrl('callback'))->assertForbidden()->assertSessionMissing('admin.email');
    }

    public function test_non_member_is_refused(): void
    {
        $this->fakeGroup(false);
        $this->googleReturns('someone@lezwatchtv.com', 'lezwatchtv.com');

        $this->get($this->adminUrl('callback'))
            ->assertForbidden()
            ->assertSessionMissing('admin.email');

        $this->assertSame(['someone@lezwatchtv.com'], $this->groupChecks);
    }

    public function test_group_api_error_is_refused(): void
    {
        $this->fakeGroup(new GroupCheckFailed('Google is down'));
        $this->googleReturns('mika@lezwatchtv.com', 'lezwatchtv.com');

        $this->get($this->adminUrl('callback'))
            ->assertForbidden()
            ->assertSessionMissing('admin.email');
    }

    public function test_member_is_let_in(): void
    {
        $this->fakeGroup(true);
        $this->googleReturns('mika@lezwatchtv.com', 'lezwatchtv.com');

        $this->get($this->adminUrl('callback'))
            ->assertRedirect($this->adminUrl())
            ->assertSessionHas('admin.email', 'mika@lezwatchtv.com');

        $this->get($this->adminUrl())->assertOk()->assertSee('Sync log');
    }

    public function test_recheck_not_called_before_interval(): void
    {
        $this->fakeGroup(false);

        $this->signedInAs(checkedAt: now()->subMinutes(1439)->getTimestamp())
            ->get($this->adminUrl())
            ->assertOk();

        $this->assertSame([], $this->groupChecks);
    }

    public function test_recheck_interval_comes_from_config(): void
    {
        config(['admin.recheck_minutes' => 15]);
        $this->fakeGroup(true);

        $this->signedInAs(checkedAt: now()->subMinutes(16)->getTimestamp())
            ->get($this->adminUrl())
            ->assertOk();

        $this->assertSame(['mika@lezwatchtv.com'], $this->groupChecks);
    }

    public function test_recheck_after_interval_signs_out_removed_member(): void
    {
        $this->fakeGroup(false);

        $this->signedInAs(checkedAt: now()->subMinutes(1441)->getTimestamp())
            ->get($this->adminUrl())
            ->assertRedirect($this->adminUrl('login'))
            ->assertSessionMissing('admin.email');

        $this->assertSame(['mika@lezwatchtv.com'], $this->groupChecks);
    }

    public function test_recheck_api_error_signs_out(): void
    {
        $this->fakeGroup(new GroupCheckFailed('Google is down'));

        $this->signedInAs(checkedAt: now()->subDays(2)->getTimestamp())
            ->get($this->adminUrl())
            ->assertRedirect($this->adminUrl('login'))
            ->assertSessionMissing('admin.email');
    }

    public function test_recheck_success_refreshes_checked_at(): void
    {
        $this->fakeGroup(true);

        $this->signedInAs(checkedAt: now()->subDays(2)->getTimestamp())
            ->get($this->adminUrl())
            ->assertOk();

        $this->assertEqualsWithDelta(now()->getTimestamp(), session('admin.checked_at'), 5);
    }

    public function test_logout_clears_session(): void
    {
        $this->signedInAs()
            ->post($this->adminUrl('logout'))
            ->assertRedirect($this->adminUrl('login'))
            ->assertSessionMissing('admin.email');
    }

    public function test_logout_requires_post(): void
    {
        $this->signedInAs()->get($this->adminUrl('logout'))->assertMethodNotAllowed();
    }

    public function test_unknown_admin_path_is_404(): void
    {
        $this->get($this->adminUrl('wp-login.php'))->assertNotFound();
        $this->signedInAs()->get($this->adminUrl('nope'))->assertNotFound();
    }

    public function test_admin_cookie_is_scoped_to_admin_path(): void
    {
        $cookie = collect($this->get($this->adminUrl('login'))->headers->getCookies())
            ->firstWhere(fn ($c) => $c->getName() === 'lwtv-admin');

        $this->assertNotNull($cookie, 'The admin should set its own session cookie.');
        $this->assertSame('/secret-door', $cookie->getPath());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
    }

    public function test_public_pages_do_not_set_admin_cookie(): void
    {
        // An admin request first, so leaked session config would show up below.
        $this->get($this->adminUrl('login'));

        foreach (['/', '/api/shows'] as $url) {
            $names = collect($this->get($url)->headers->getCookies())->map->getName();
            $this->assertNotContains('lwtv-admin', $names, "{$url} set the admin cookie.");
            foreach ($this->get($url)->headers->getCookies() as $c) {
                $this->assertSame('/', $c->getPath(), "{$url} set a cookie with the admin path.");
            }
        }
    }

    public function test_cookie_driver_keeps_every_admin_cookie_on_the_admin_path(): void
    {
        // Production uses the cookie driver, which stores the session in a second cookie.
        config(['session.driver' => 'cookie']);
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');

        $cookies = $this->get($this->adminUrl('login'))->headers->getCookies();

        $this->assertGreaterThanOrEqual(2, count($cookies));
        foreach ($cookies as $cookie) {
            $this->assertSame('/secret-door', $cookie->getPath(), "{$cookie->getName()} escaped the admin path.");
        }
    }
}
