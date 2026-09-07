<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Raccount\Sso\Client\Dto\UserInfo;
use Raccount\Sso\Contracts\UserResolver;
use Raccount\Sso\Exceptions\AccountLinkageDenied;
use Raccount\Sso\Exceptions\EmailNotVerified;
use Raccount\Sso\Models\RaccountAccount;
use Raccount\Sso\Tests\Fixtures\User;

function resolveUser(UserInfo $info): Authenticatable
{
    return app(UserResolver::class)->resolve($info);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function userInfo(array $overrides = []): UserInfo
{
    return UserInfo::fromClaimSet(array_merge([
        'sub' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
        'name' => 'Budi Santoso',
        'email' => 'budi@example.com',
        'email_verified' => true,
    ], $overrides));
}

it('returns the linked user for an existing sub and refreshes the snapshot', function (): void {
    $user = User::create(['name' => 'Old Name', 'email' => 'budi@example.com']);
    RaccountAccount::query()->create([
        'raccount_sub' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
        'user_type' => RaccountAccount::morphTypeFor($user),
        'user_id' => $user->getAuthIdentifier(),
        'status' => RaccountAccount::STATUS_ACTIVE,
        'name' => 'Old Name',
    ]);

    $resolved = resolveUser(userInfo(['name' => 'New Name']));

    expect($resolved->id)->toBe($user->id);

    $account = RaccountAccount::query()->where('raccount_sub', '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0')->first();

    expect($account->name)->toBe('New Name')
        ->and($account->email)->toBe('budi@example.com');
});

it('denies login for a suspended linked account', function (): void {
    $user = User::create(['name' => 'Budi', 'email' => 'budi@example.com']);
    RaccountAccount::query()->create([
        'raccount_sub' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
        'user_type' => RaccountAccount::morphTypeFor($user),
        'user_id' => $user->getAuthIdentifier(),
        'status' => RaccountAccount::STATUS_SUSPENDED,
    ]);

    resolveUser(userInfo());
})->throws(AccountLinkageDenied::class);

it('removes a stale link whose local user no longer exists', function (): void {
    RaccountAccount::query()->create([
        'raccount_sub' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
        'user_type' => (new User)->getMorphClass(),
        'user_id' => 99999,
        'status' => RaccountAccount::STATUS_ACTIVE,
    ]);

    $resolved = resolveUser(userInfo());

    expect($resolved)->toBeInstanceOf(User::class)
        ->and($resolved->email)->toBe('budi@example.com')
        ->and(RaccountAccount::query()->where('user_id', 99999)->exists())->toBeFalse();
});

it('rejects an unverified email when required', function (): void {
    resolveUser(userInfo(['email_verified' => false]));
})->throws(EmailNotVerified::class);

it('rejects a missing email verification claim when required', function (): void {
    resolveUser(userInfo(['email' => null, 'email_verified' => null]));
})->throws(EmailNotVerified::class);

it('links an existing local user with a matching verified email', function (): void {
    $user = User::create(['name' => 'Budi', 'email' => 'BUDI@example.com']);

    $resolved = resolveUser(userInfo(['email' => 'budi@example.com']));

    expect($resolved->id)->toBe($user->id);

    expect(RaccountAccount::query()
        ->where('raccount_sub', '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0')
        ->where('user_id', $user->id)
        ->exists())->toBeTrue();
});

it('denies linking an existing email when auto-link is disabled', function (): void {
    config()->set('raccount-sso.user.auto_link_verified_email', false);
    User::create(['name' => 'Budi', 'email' => 'budi@example.com']);

    resolveUser(userInfo());
})->throws(AccountLinkageDenied::class);

it('provisions a new local user with mapped attributes', function (): void {
    $resolved = resolveUser(userInfo());

    expect($resolved)->toBeInstanceOf(User::class)
        ->and($resolved->name)->toBe('Budi Santoso')
        ->and($resolved->email)->toBe('budi@example.com')
        ->and(RaccountAccount::query()->where('raccount_sub', $resolved ? '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0' : '')->exists())->toBeTrue();
});

it('honours a custom attribute map', function (): void {
    config()->set('raccount-sso.user.attributes', ['name' => 'name']);

    $resolved = resolveUser(userInfo());

    expect($resolved->name)->toBe('Budi Santoso')
        ->and($resolved->email)->toBeNull();
});
