<?php

namespace Raccount\Sso\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Raccount\Sso\Client\RaccountClient;
use Raccount\Sso\Contracts\UserResolver;
use Raccount\Sso\Tokens\TokenService;

final class CallbackController
{
    public function __construct(
        private readonly RaccountClient $client,
        private readonly TokenService $tokens,
    ) {}

    public function __invoke(Request $request, UserResolver $resolver): RedirectResponse
    {
        if ($request->filled('error')) {
            $request->session()->flash(
                'raccount-sso.error',
                (string) ($request->query('error_description') ?? $request->query('error')),
            );

            return redirect()->route((string) config('raccount-sso.redirects.on_error', 'login'));
        }

        $state = $request->session()->pull('raccount-sso.state');
        $verifier = $request->session()->pull('raccount-sso.verifier');

        if (! is_string($state) || ! is_string($verifier)
            || ! hash_equals($state, (string) $request->query('state', ''))) {
            abort(419, 'The SSO state parameter is invalid or has expired.');
        }

        $tokens = $this->client->exchangeCode((string) $request->query('code', ''), $verifier);
        $userinfo = $this->client->userinfo($tokens->accessToken);
        $user = $resolver->resolve($userinfo);

        $this->tokens->storeFor($user, $userinfo, $tokens);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended((string) config('raccount-sso.redirects.after_login', '/home'));
    }
}
