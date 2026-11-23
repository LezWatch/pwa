<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives the admin its own session cookie, scoped to the admin path, so the
 * public pages never see or set it. Runs before StartSession.
 */
class UseAdminSession
{
    public const COOKIE = 'lwtv-admin';

    public function handle(Request $request, Closure $next): Response
    {
        $previous = config('session');

        $this->apply(array_merge($previous, [
            'cookie' => self::COOKIE,
            'path' => '/'.config('admin.path'),
            'lifetime' => max(1, (int) config('admin.recheck_minutes')),
            'expire_on_close' => false,
            'http_only' => true,
            'same_site' => 'lax',
            // TLS ends at the host nginx, so the request can't tell us; APP_URL can.
            'secure' => ($previous['secure'] ?? null) ?? str_starts_with((string) config('app.url'), 'https://'),
        ]));

        try {
            $response = $next($request);
            $this->moveQueuedCookiesTo($response);

            return $response;
        } finally {
            // A long-lived worker (or a test) reuses the app between requests;
            // put everything back so public pages get the defaults and nothing
            // queued here can follow them. Under php-fpm each request starts fresh.
            app('cookie')->flushQueuedCookies();
            $this->apply($previous);
        }
    }

    /**
     * The cookie driver queues the session payload on the shared cookie jar.
     * Attach it to this response now (EncryptCookies, further out, still
     * encrypts it) so the jar can be emptied before the next request.
     */
    private function moveQueuedCookiesTo(Response $response): void
    {
        foreach (app('cookie')->getQueuedCookies() as $cookie) {
            $response->headers->setCookie($cookie);
        }
    }

    /**
     * Session config drives the session cookie and, for the cookie driver,
     * the cookie jar's default path (where the session payload goes). A store
     * built before this ran keeps its old cookie name, so rename it too.
     */
    private function apply(array $session): void
    {
        config(['session' => $session]);

        app('cookie')->setDefaultPathAndDomain(
            $session['path'], $session['domain'] ?? null, $session['secure'] ?? false, $session['same_site'] ?? null,
        );

        foreach (app('session')->getDrivers() as $store) {
            $store->setName($session['cookie']);
        }
    }
}
