<?php

use Illuminate\Support\Facades\Http;
use Raccount\Sso\Client\RaccountClient;
use Raccount\Sso\Exceptions\InvalidGrant;
use Raccount\Sso\Exceptions\TokenExchangeFailed;
use Raccount\Sso\Facades\Raccount;

it('builds the authorization url with pkce and scopes', function (): void {
    $url = app(RaccountClient::class)->authorizationUrl('state-123', 'challenge-abc');

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($url)->toStartWith('https://account.test/oauth/authorize')
        ->and($query)->toMatchArray([
            'response_type' => 'code',
            'client_id' => '11111111-1111-1111-1111-111111111111',
            'redirect_uri' => 'https://app.test/raccount/callback',
            'scope' => 'profile email',
            'state' => 'state-123',
            'code_challenge' => 'challenge-abc',
            'code_challenge_method' => 'S256',
        ])
        ->and($query)->not->toHaveKey('prompt');
});

it('includes the prompt parameter when configured', function (): void {
    $url = app(RaccountClient::class)->authorizationUrl('s', 'c', 'consent');

    expect($url)->toContain('prompt=consent');
});

it('exchanges an authorization code for a token pair', function (): void {
    Http::fake(['account.test/oauth/token' => Http::response([
        'token_type' => 'Bearer',
        'expires_in' => 900,
        'access_token' => 'at',
        'refresh_token' => 'rt',
        'scope' => 'profile email',
    ])]);

    $pair = app(RaccountClient::class)->exchangeCode('the-code', 'the-verifier');

    expect($pair->accessToken)->toBe('at')
        ->and($pair->refreshToken)->toBe('rt');

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return str_ends_with($request->url(), '/oauth/token')
            && $body['grant_type'] === 'authorization_code'
            && $body['code'] === 'the-code'
            && $body['code_verifier'] === 'the-verifier'
            && $body['redirect_uri'] === 'https://app.test/raccount/callback'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('11111111-1111-1111-1111-111111111111:'.str_repeat('a', 64)));
    });
});

it('refreshes a token and maps invalid_grant to the typed exception', function (): void {
    Http::fake(['account.test/oauth/token' => Http::response([
        'error' => 'invalid_grant',
        'error_description' => 'The refresh token has been revoked.',
    ], 400)]);

    app(RaccountClient::class)->refresh('stale-token');
})->throws(InvalidGrant::class);

it('maps other oauth errors to token exchange failures', function (): void {
    Http::fake(['account.test/oauth/token' => Http::response([
        'error' => 'invalid_client',
        'error_description' => 'Client authentication failed.',
    ], 401)]);

    app(RaccountClient::class)->clientCredentialsToken('sync:read');
})->throws(TokenExchangeFailed::class);

it('obtains a machine token via client credentials', function (): void {
    Http::fake(['account.test/oauth/token' => Http::response([
        'token_type' => 'Bearer',
        'expires_in' => 3600,
        'access_token' => 'm2m',
        'scope' => 'sync:read',
    ])]);

    $pair = app(RaccountClient::class)->clientCredentialsToken('sync:read');

    expect($pair->accessToken)->toBe('m2m');

    Http::assertSent(fn ($request): bool => $request->data()['grant_type'] === 'client_credentials'
        && $request->data()['scope'] === 'sync:read');
});

it('paginates the directory', function (): void {
    Http::fake(['account.test/api/v1/directory/users*' => Http::response([
        'data' => [[
            'sub' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
            'name' => 'Budi',
            'email' => 'budi@example.com',
            'email_verified' => true,
            'status' => 'active',
            'updated_at' => '2026-09-07T00:00:00Z',
        ]],
        'next_cursor' => 'CUR1',
    ])]);

    $page = app(RaccountClient::class)->directoryPage('m2m-token', null, null, 200);

    expect($page->data)->toHaveCount(1)
        ->and($page->nextCursor)->toBe('CUR1');
});

it('resolves the client through the facade', function (): void {
    Http::fake(['account.test/api/v1/ping' => Http::response(['status' => 'ok'])]);

    expect(Raccount::ping())->toBeTrue();
});
