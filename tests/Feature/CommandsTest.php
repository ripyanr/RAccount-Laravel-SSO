<?php

use Illuminate\Support\Facades\Http;
use Raccount\Sso\Models\RaccountWebhookEvent;

it('passes raccount:check when everything is configured and reachable', function (): void {
    // The base TestCase enables webhooks globally, so a secret must be present
    // for the webhook-secrets check (it reads config at run time).
    config()->set('raccount-sso.webhooks.secrets', ['whsec_test']);

    Http::fake(['account.test/api/v1/ping' => Http::response(['status' => 'ok'])]);

    $this->artisan('raccount:check')->assertSuccessful();
});

it('fails raccount:check when the server is unreachable', function (): void {
    Http::fake(['account.test/api/v1/ping' => Http::response(['status' => 'error'], 500)]);

    $this->artisan('raccount:check')->assertFailed();
});

it('fails raccount:check when the client secret is missing', function (): void {
    config()->set('raccount-sso.client.secret', null);

    $this->artisan('raccount:check')->assertFailed();
});

it('fails raccount:check when webhooks are enabled without secrets', function (): void {
    config()->set('raccount-sso.webhooks.enabled', true);
    config()->set('raccount-sso.webhooks.secrets', []);

    $this->artisan('raccount:check')->assertFailed();
});

it('syncs the directory', function (): void {
    config()->set('raccount-sso.directory.enabled', true);

    Http::fake([
        'account.test/oauth/token' => Http::response(['expires_in' => 3600, 'access_token' => 'm2m']),
        'account.test/api/v1/directory/users*' => Http::response([
            'data' => [[
                'sub' => 'sub-1',
                'name' => 'Budi',
                'email' => 'budi@example.com',
                'email_verified' => true,
                'status' => 'active',
                'updated_at' => '2026-09-07T00:00:00Z',
            ]],
            'next_cursor' => null,
        ]),
    ]);

    $this->artisan('raccount:directory:sync')->assertSuccessful();
});

it('fails cleanly when --since is not a valid timestamp', function (): void {
    $this->artisan('raccount:directory:sync', ['--since' => 'not-a-timestamp'])
        ->expectsOutputToContain('Invalid --since value')
        ->assertFailed();
});

it('prunes old webhook events', function (): void {
    RaccountWebhookEvent::query()->create([
        'event_id' => '01JABCDEFGHJKMNPQRSTVWXYZ',
        'event_type' => 'user.updated',
        'payload' => [],
        'received_at' => now()->subDays(45),
    ]);

    $this->artisan('raccount:prune-webhooks', ['--days' => 30])->assertSuccessful();

    expect(RaccountWebhookEvent::query()->count())->toBe(0);
});
