<?php

namespace Raccount\Sso\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Raccount\Sso\Models\RaccountAccount;
use Symfony\Component\HttpFoundation\Response;

class EnsureRaccountAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || config('raccount-sso.middleware.enforce_status') !== true) {
            return $next($request);
        }

        // Enforcement is scoped to users who actually have a linked RAccount
        // identity: mixed-mode applications keep purely local users working.
        $account = RaccountAccount::query()
            ->forUser($user)
            ->first();

        if ($account === null || $account->status === RaccountAccount::STATUS_ACTIVE) {
            return $next($request);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->flash(
            'raccount-sso.error',
            'Your RAccount identity is suspended or deleted on this application.',
        );

        return new RedirectResponse(
            route((string) config('raccount-sso.redirects.on_error', 'login')),
        );
    }
}
