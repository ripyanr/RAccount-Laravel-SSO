<?php

use Illuminate\Support\Facades\Http;
use Raccount\Sso\Client\Dto\TokenPair;
use Raccount\Sso\Client\Dto\UserInfo;
use Raccount\Sso\Exceptions\InvalidGrant;
use Raccount\Sso\Models\RaccountAccount;
use Raccount\Sso\Tests\Fixtures\User;
use Raccount\Sso\Tokens\TokenService;

function tokenPair(string $access = 'at', string $refresh = 'rt'): TokenPair
{
    return new TokenPair($access, $refresh, 900, ['profile', 'email'], now()->toImmutable());
}

function userInfoForTokens(): UserInfo
{
    return UserInfo::fromClaimSet([
        'sub' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
        'name' => 'Budi',
        'email' => 'budi@example.com',
        'email_verified' => true,
    ]);
}

it('stores tokens for a user with snapshot and expiry', function (): void {
    $user = User::create(['name' => 'Budi', 'email' => 'budi@example.com']);

    $account = app(TokenService::class)->storeFor($user, userInfoForTokens(), tokenPair());

    expect($account->access_token)->toBe('at')
        ->and($account->refresh_token)->toBe('rt')
        ->and($account->access_expires_at)->not->toBeNull()
        ->and($account->scopes)->toBe(['profile', 'email'])
        ->and($account->status)->toBe(RaccountAccount::STATUS_ACTIVE)
        ->and($account->last_login_at)->not->toBeNull()
        ->and($account->user_id)->toBe($user->id);
});

it('rotates refresh tokens and persists the new pair', function (): void {
    $user = User::create(['name' => 'Budi', 'email' => 'budi@example.com']);
    $account = app(TokenService::class)->storeFor($user, userInfoForTokens(), tokenPair('old-at', 'old-rt'));

    Http::fake(['account.test/oauth/token' => Http::response([
        'token_type' => 'Bearer',
        'expires_in' => 900,
        'access_token' => 'new-at',
        'refresh_token' => 'new-rt',
        'scope' => 'profile email',
    ])]);

    $fresh = app(TokenService::class)->refresh($account);

    expect($fresh->accessToken)->toBe('new-at');

    $account->refresh();

    expect($account->access_token)->toBe('new-at')
        ->and($account->refresh_token)->toBe('new-rt');
});

it('clears stored tokens when the refresh grant is invalid', function (): void {
    $user = User::create(['name' => 'Budi', 'email' => 'budi@example.com']);
    $account = app(TokenService::class)->storeFor($user, userInfoForTokens(), tokenPair('old-at', 'old-rt'));

    Http::fake(['account.test/oauth/token' => Http::response([
        'error' => 'invalid_grant',
        'error_description' => 'The refresh token has been revoked.',
    ], 400)]);

    try {
        app(TokenService::class)->refresh($account);
        $this->fail('InvalidGrant was not thrown.');
    } catch (InvalidGrant) {
        $account->refresh();

        expect($account->access_token)->toBeNull()
            ->and($account->refresh_token)->toBeNull()
            ->and($account->access_expires_at)->toBeNull();
    }
});

it('revokes both tokens best-effort and clears them locally', function (): void {
    $user = User::create(['name' => 'Budi', 'email' => 'budi@example.com']);
    $account = app(TokenService::class)->storeFor($user, userInfoForTokens(), tokenPair());

    Http::fake(['account.test/oauth/revoke' => Http::response(['error' => 'invalid_client'], 401)]);

    app(TokenService::class)->revokeAll($account);

    $account->refresh();

    expect($account->access_token)->toBeNull()
        ->and($account->refresh_token)->toBeNull();
});
