<?php

namespace Raccount\Sso\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Raccount\Sso\Models\RaccountAccount;
use Raccount\Sso\Tokens\TokenService;

final class LogoutController
{
    public function __construct(
        private readonly TokenService $tokens,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user !== null) {
            $account = RaccountAccount::query()->forUser($user)->first();

            if ($account !== null) {
                $this->tokens->revokeAll($account);
            }
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->to((string) config('raccount-sso.redirects.after_logout', '/'));
    }
}
