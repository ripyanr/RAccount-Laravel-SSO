<?php

use Illuminate\Support\Facades\Http;
use Raccount\Sso\Client\RaccountClient;
use Raccount\Sso\Exceptions\ConfigurationInvalid;
use Raccount\Sso\Exceptions\RateLimited;
use Raccount\Sso\Exceptions\RequestFailed;
use Raccount\Sso\Exceptions\UserInfoFailed;

it('rejects an empty base url', function (): void {
    config()->set('raccount-sso.server.base_url', null);

    app(RaccountClient::class)->ping();
})->throws(ConfigurationInvalid::class);

it('rejects plain http outside local development', function (): void {
    config()->set('app.env', 'production');
    config()->set('raccount-sso.server.base_url', 'http://account.test');

    app(RaccountClient::class)->ping();
})->throws(ConfigurationInvalid::class);

it('allows plain http in local development when explicitly opted in', function (): void {
    config()->set('app.env', 'local');
    config()->set('raccount-sso.server.base_url', 'http://account.test');
    config()->set('raccount-sso.server.allow_insecure', true);
    Http::fake(['account.test/api/v1/ping' => Http::response(['status' => 'ok'])]);

    expect(app(RaccountClient::class)->ping())->toBeTrue();
});

it('pings the server', function (): void {
    Http::fake(['account.test/api/v1/ping' => Http::response(['status' => 'ok'])]);

    expect(app(RaccountClient::class)->ping())->toBeTrue();
});

it('parses userinfo claims', function (): void {
    Http::fake(['account.test/api/v1/userinfo' => Http::response([
        'sub' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
        'name' => 'Budi',
        'email' => 'budi@example.com',
        'email_verified' => true,
    ])]);

    $info = app(RaccountClient::class)->userinfo('token');

    expect($info->sub)->toBe('0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0')
        ->and($info->emailVerified)->toBeTrue();
});

it('maps userinfo problem details to an exception', function (): void {
    Http::fake(['account.test/api/v1/userinfo' => Http::response([
        'type' => 'https://raccount.reducates.id/problems/insufficient-scope',
        'title' => 'Insufficient scope',
        'status' => 403,
        'detail' => 'The access token does not carry a scope required by this endpoint.',
    ], 403)]);

    app(RaccountClient::class)->userinfo('token');
})->throws(UserInfoFailed::class);

it('introspects a token', function (): void {
    Http::fake(['account.test/oauth/introspect' => Http::response([
        'active' => true,
        'token_type' => 'access_token',
        'scope' => 'profile email',
    ])]);

    $result = app(RaccountClient::class)->introspect('token');

    expect($result->active)->toBeTrue()
        ->and($result->scopes)->toBe(['profile', 'email']);

    Http::assertSent(function ($request): bool {
        return str_ends_with($request->url(), '/oauth/introspect')
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('11111111-1111-1111-1111-111111111111:'.str_repeat('a', 64)));
    });
});

it('revokes a token and reports oauth errors', function (): void {
    Http::fake(['account.test/oauth/revoke' => Http::response(['error' => 'invalid_client'], 401)]);

    expect(app(RaccountClient::class)->revoke('token', 'refresh_token'))->toBeFalse();
});

it('retries server errors and then succeeds', function (): void {
    Http::fake([
        'account.test/api/v1/ping' => Http::sequence()
            ->push(['error' => 'error'], 500)
            ->push(['status' => 'ok']),
    ]);

    expect(app(RaccountClient::class)->ping())->toBeTrue();
});

it('gives up after the configured attempts', function (): void {
    config()->set('raccount-sso.http.attempts', 2);
    config()->set('raccount-sso.http.backoff_ms', 1);
    Http::fake(['account.test/api/v1/ping' => Http::response(['error' => 'error'], 500)]);

    app(RaccountClient::class)->ping();
})->throws(RequestFailed::class);

it('surfaces rate limits with the retry-after header', function (): void {
    Http::fake(['account.test/api/v1/userinfo' => Http::response(
        ['type' => 'https://raccount.reducates.id/problems/rate-limited', 'title' => 'Rate limited', 'status' => 429, 'detail' => 'Slow down.'],
        429,
        ['Retry-After' => '17'],
    )]);

    try {
        app(RaccountClient::class)->userinfo('token');
        $this->fail('RateLimited was not thrown.');
    } catch (RateLimited $exception) {
        expect($exception->retryAfterSeconds)->toBe(17);
    }
});
