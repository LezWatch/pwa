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
        ]));

        try {
            return $next($request);
        } finally {
            // Long-lived workers (and tests) reuse these between requests;
            // put them back so public pages get the defaults.
            $this->apply($previous);
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
