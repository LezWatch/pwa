<?php

namespace App\Http\Controllers\Admin;

use App\Admin\GroupCheckFailed;
use App\Admin\GroupMembership;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

class AuthController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        if ($request->session()->has('admin.email')) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.login');
    }

    public function redirect(): SymfonyRedirect
    {
        return $this->google()
            ->with(['hd' => config('admin.domain'), 'prompt' => 'select_account'])
            ->redirect();
    }

    public function callback(Request $request, GroupMembership $group): RedirectResponse|Response
    {
        try {
            $user = $this->google()->user();
        } catch (Throwable $e) {
            // Bad or replayed state, a cancelled consent screen, or Google trouble.
            Log::warning('Admin sign-in failed at Google', ['reason' => $e->getMessage()]);

            return $this->refuse($request, 'Google sign-in did not complete. Please try again.');
        }

        $email = (string) $user->getEmail();
        $raw = $user->getRaw();

        // The hd URL parameter can be stripped; the token's hd claim can't.
        if (($raw['hd'] ?? null) !== config('admin.domain') || ($raw['email_verified'] ?? false) !== true) {
            return $this->refuse($request, 'Sign in with your '.config('admin.domain').' account.');
        }

        try {
            $isMember = $group->isMember($email);
        } catch (GroupCheckFailed $e) {
            report($e);

            return $this->refuse($request, 'We could not check your access right now. Please try again later.');
        }

        if (! $isMember) {
            return $this->refuse($request, 'Your account is not on the admin list.');
        }

        $request->session()->regenerate();
        $request->session()->put([
            'admin.email' => $email,
            'admin.checked_at' => now()->getTimestamp(),
        ]);

        return redirect()->route('admin.dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }

    private function refuse(Request $request, string $reason): Response
    {
        $request->session()->invalidate();

        return response()->view('admin.refused', ['reason' => $reason], 403);
    }

    private function google(): GoogleProvider
    {
        /** @var GoogleProvider */
        return Socialite::driver('google')->redirectUrl(route('admin.callback'));
    }
}
