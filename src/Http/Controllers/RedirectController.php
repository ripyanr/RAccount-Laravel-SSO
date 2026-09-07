<?php

namespace Raccount\Sso\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Raccount\Sso\Client\RaccountClient;
use Raccount\Sso\Flow\Pkce;

final class RedirectController
{
    public function __construct(
        private readonly RaccountClient $client,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $state = bin2hex(random_bytes(32));
        $verifier = Pkce::verifier();

        $request->session()->put('raccount-sso.state', $state);
        $request->session()->put('raccount-sso.verifier', $verifier);

        return redirect()->away(
            $this->client->authorizationUrl($state, Pkce::challenge($verifier), config('raccount-sso.prompt')),
        );
    }
}
