<?php

use Illuminate\Support\Facades\DB;
use Raccount\Sso\Models\RaccountAccount;
use Raccount\Sso\Models\RaccountWebhookEvent;
use Raccount\Sso\Tests\Fixtures\User;

it('encrypts tokens at rest', function (): void {
    $user = User::create(['name' => 'Budi', 'email' => 'budi@example.com']);

    $account = RaccountAccount::query()->create([
        'raccount_sub' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
        'user_type' => RaccountAccount::morphTypeFor($user),
        'user_id' => $user->getAuthIdentifier(),
        'email' => 'budi@example.com',
        'name' => 'Budi',
        'status' => RaccountAccount::STATUS_ACTIVE,
        'access_token' => 'secret-access-token',
        'refresh_token' => 'secret-refresh-token',
        'scopes' => ['profile', 'email'],
    ]);

    expect($account->access_token)->toBe('secret-access-token')
        ->and($account->refresh_token)->toBe('secret-refresh-token')
        ->and($account->isActive())->toBeTrue();

    $raw = DB::table('raccount_accounts')->where('id', $account->id)->first();

    expect($raw->access_token)->not->toBe('secret-access-token')
        ->and($raw->refresh_token)->not->toBe('secret-refresh-token');
});

it('relates an account to its morphed user', function (): void {
    $user = User::create(['name' => 'Budi', 'email' => 'budi@example.com']);

    RaccountAccount::query()->create([
        'raccount_sub' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
        'user_type' => RaccountAccount::morphTypeFor($user),
        'user_id' => $user->getAuthIdentifier(),
        'status' => RaccountAccount::STATUS_ACTIVE,
    ]);

    $account = RaccountAccount::query()->forUser($user)->first();

    expect($account->user)->toBeInstanceOf(User::class)
        ->and($account->user->id)->toBe($user->id);
});

it('stores webhook event payloads as json', function (): void {
    $event = RaccountWebhookEvent::query()->create([
        'event_id' => '01JABCDEFGHJKMNPQRSTVWXYZ',
        'event_type' => 'user.updated',
        'payload' => ['data' => ['sub' => 'x']],
        'received_at' => now(),
    ]);

    expect($event->payload)->toBe(['data' => ['sub' => 'x']])
        ->and($event->processed_at)->toBeNull();
});
