<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Raccount\Sso\Events\UserSignedOut;
use Raccount\Sso\Events\UserUpdated;
use Raccount\Sso\Events\WebhookReceived;
use Raccount\Sso\Models\RaccountAccount;
use Raccount\Sso\Models\RaccountWebhookEvent;
use Raccount\Sso\Tests\Fixtures\User;

function postWebhook(array $body, array $headers = [], ?string $secret = 'whsec_test', ?string $timestamp = null): TestResponse
{
    config()->set('raccount-sso.webhooks.enabled', true);
    config()->set('raccount-sso.webhooks.secrets', $secret === null ? [] : [$secret]);

    $raw = json_encode($body, JSON_THROW_ON_ERROR);
    $timestamp ??= CarbonImmutable::now('UTC')->toIso8601ZuluString();

    $server = test()->transformHeadersToServerVars(array_merge([
        'X-RAccount-Event-Id' => (string) ($body['id'] ?? '01JABCDEFGHJKMNPQRSTVWXYZ'),
        'X-RAccount-Event-Type' => (string) ($body['type'] ?? 'user.updated'),
        'X-RAccount-Timestamp' => $timestamp,
        'X-RAccount-Signature' => 'sha256='.hash_hmac('sha256', $raw, $secret ?? ''),
    ], $headers));

    return test()->call('POST', 'raccount/webhook', [], [], [], $server, $raw);
}

it('accepts a signed webhook, records it, and dispatches events', function (): void {
    Event::fake([WebhookReceived::class, UserUpdated::class]);

    postWebhook([
        'id' => '01JABCDEFGHJKMNPQRSTVWXYZ',
        'type' => 'user.updated',
        'occurred_at' => CarbonImmutable::now('UTC')->toIso8601ZuluString(),
        'actor' => ['type' => 'user', 'id' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0'],
        'data' => ['sub' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0', 'name' => 'New Name'],
        'changed' => ['name'],
    ])->assertOk();

    Event::assertDispatched(WebhookReceived::class);
    Event::assertDispatched(UserUpdated::class, fn ($event): bool => $event->payload->sub() === '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0');

    expect(RaccountWebhookEvent::query()->find('01JABCDEFGHJKMNPQRSTVWXYZ')->processed_at)->not->toBeNull();
});

it('returns 200 without dispatching when the signature is invalid', function (): void {
    Event::fake([WebhookReceived::class]);

    postWebhook(['id' => '01JABCDEFGHJKMNPQRSTVWXYZ', 'type' => 'user.updated', 'data' => []], ['X-RAccount-Signature' => 'sha256='.str_repeat('0', 64)])
        ->assertOk();

    Event::assertNotDispatched(WebhookReceived::class);
});

it('ignores a replayed event id that was already processed', function (): void {
    Event::fake([WebhookReceived::class]);

    postWebhook(['id' => '01JABCDEFGHJKMNPQRSTVWXYZ', 'type' => 'user.updated', 'data' => []])->assertOk();
    postWebhook(['id' => '01JABCDEFGHJKMNPQRSTVWXYZ', 'type' => 'user.updated', 'data' => []])->assertOk();

    Event::assertDispatchedTimes(WebhookReceived::class, 1);
});

it('updates the linked account status from the built-in listener', function (): void {
    $user = User::create(['name' => 'Budi', 'email' => 'budi@example.com']);
    RaccountAccount::query()->create([
        'raccount_sub' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
        'user_type' => RaccountAccount::morphTypeFor($user),
        'user_id' => $user->id,
        'status' => RaccountAccount::STATUS_ACTIVE,
        'name' => 'Budi',
    ]);

    postWebhook([
        'id' => '01JZZZZZZZZZZZZZZZZZZZZZZZZ',
        'type' => 'user.suspended',
        'occurred_at' => CarbonImmutable::now('UTC')->toIso8601ZuluString(),
        'actor' => ['type' => 'admin', 'id' => 'x'],
        'data' => ['sub' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0', 'name' => 'Budi'],
    ])->assertOk();

    expect($user->fresh()->name)->toBe('Budi')
        ->and(RaccountAccount::query()->where('raccount_sub', '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0')->value('status'))
        ->toBe(RaccountAccount::STATUS_SUSPENDED);
});

it('refreshes the snapshot on user.updated without resurrecting a suspended account', function (): void {
    $user = User::create(['name' => 'Budi', 'email' => 'budi@example.com']);
    RaccountAccount::query()->create([
        'raccount_sub' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
        'user_type' => RaccountAccount::morphTypeFor($user),
        'user_id' => $user->id,
        'status' => RaccountAccount::STATUS_SUSPENDED,
        'name' => 'Budi',
    ]);

    postWebhook([
        'id' => '01JYYYYYYYYYYYYYYYYYYYYYYYY',
        'type' => 'user.updated',
        'occurred_at' => CarbonImmutable::now('UTC')->toIso8601ZuluString(),
        'actor' => ['type' => 'admin', 'id' => 'x'],
        'data' => ['sub' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0', 'name' => 'New Name'],
    ])->assertOk();

    $account = RaccountAccount::query()->where('raccount_sub', '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0')->first();

    expect($account->name)->toBe('New Name')
        ->and($account->status)->toBe(RaccountAccount::STATUS_SUSPENDED);
});

it('dispatches UserSignedOut for a user.signed_out delivery', function (): void {
    Event::fake([WebhookReceived::class, UserSignedOut::class]);

    postWebhook([
        'id' => '01M1ZK8X9CPDFN51CBZH2B7CM0',
        'type' => 'user.signed_out',
        'occurred_at' => '2026-09-08T04:08:59Z',
        'actor' => ['type' => 'admin', 'id' => '01a07bdd-b568-70fe-8f90-63adaf2381a4'],
        'data' => [
            'sub' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
            'name' => 'Reducates Admin',
            'email' => 'superadmin@reducates.com',
            'email_verified' => true,
            'status' => 'active',
            'updated_at' => '2026-09-08T03:57:14Z',
        ],
    ])->assertOk();

    Event::assertDispatched(WebhookReceived::class);
    Event::assertDispatched(UserSignedOut::class, fn ($event): bool => $event->payload->sub() === '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0'
        && $event->payload->occurredAt?->toIso8601ZuluString() === '2026-09-08T04:08:59Z');

    expect(RaccountWebhookEvent::query()->find('01M1ZK8X9CPDFN51CBZH2B7CM0')->processed_at)->not->toBeNull();
});

it('leaves the linked account untouched on user.signed_out', function (): void {
    $user = User::create(['name' => 'Budi', 'email' => 'budi@example.com']);
    RaccountAccount::query()->create([
        'raccount_sub' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
        'user_type' => RaccountAccount::morphTypeFor($user),
        'user_id' => $user->id,
        'status' => RaccountAccount::STATUS_ACTIVE,
        'name' => 'Budi',
    ]);

    postWebhook([
        'id' => '01M1ZK8X9CPDFN51CBZH2B7CM0',
        'type' => 'user.signed_out',
        'occurred_at' => '2026-09-08T04:08:59Z',
        'actor' => ['type' => 'admin', 'id' => '01a07bdd-b568-70fe-8f90-63adaf2381a4'],
        'data' => [
            'sub' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
            'name' => 'Reducates Admin',
            'email' => 'superadmin@reducates.com',
            'email_verified' => true,
            'status' => 'active',
            'updated_at' => '2026-09-08T03:57:14Z',
        ],
    ])->assertOk();

    $account = RaccountAccount::query()->where('raccount_sub', '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0')->first();

    expect($account->status)->toBe(RaccountAccount::STATUS_ACTIVE)
        ->and($account->name)->toBe('Budi');
});

it('returns 500 when a listener throws so the server retries', function (): void {
    Event::listen(UserUpdated::class, static function (): void {
        throw new RuntimeException('listener exploded');
    });

    postWebhook(['id' => '01JAAAAAAAAAAAAAAAAAAAAAAAA', 'type' => 'user.updated', 'data' => []])->assertStatus(500);

    expect(RaccountWebhookEvent::query()->find('01JAAAAAAAAAAAAAAAAAAAAAAAA')->processed_at)->toBeNull();
});
