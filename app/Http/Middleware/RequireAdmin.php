<?php

namespace App\Http\Middleware;

use App\Admin\GroupCheckFailed;
use App\Admin\GroupMembership;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets in a signed-in admin, rechecking group membership every
 * admin.recheck_minutes. Fails closed: a failed check signs them out.
 */
class RequireAdmin
{
    public function __construct(private GroupMembership $group) {}

    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->session();
        $email = $session->get('admin.email');

        if (! is_string($email) || $email === '') {
            return redirect()->route('admin.login');
        }

        $checkedAt = (int) $session->get('admin.checked_at', 0);
        $dueAt = $checkedAt + (int) config('admin.recheck_minutes') * 60;

        if (now()->getTimestamp() >= $dueAt) {
            try {
                $stillMember = $this->group->isMember($email);
            } catch (GroupCheckFailed $e) {
                report($e);
                $stillMember = false;
            }

            if (! $stillMember) {
                $session->invalidate();

                return redirect()->route('admin.login')
                    ->with('admin.notice', 'Your access needs to be checked again. Please sign in.');
            }

            $session->put('admin.checked_at', now()->getTimestamp());
        }

        return $next($request);
    }
}
