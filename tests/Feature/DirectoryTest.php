<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Raccount\Sso\Directory\ClientCredentialsManager;
use Raccount\Sso\Directory\DirectorySyncService;
use Raccount\Sso\Events\DirectoryUserRetrieved;

function directoryRecord(string $sub, string $name): array
{
    return [
        'sub' => $sub,
        'name' => $name,
        'email' => strtolower(str_replace(' ', '.', $name)).'@example.com',
        'email_verified' => true,
        'status' => 'active',
        'updated_at' => '2026-09-07T00:00:00Z',
    ];
}

it('walks directory pages until the cursor is exhausted', function (): void {
    config()->set('raccount-sso.directory.enabled', true);

    Http::fake([
        'account.test/oauth/token' => Http::response([
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'access_token' => 'm2m-token',
            'scope' => 'sync:read',
        ]),
        'account.test/api/v1/directory/users*' => Http::sequence()
            ->push(['data' => [directoryRecord('sub-1', 'Budi One')], 'next_cursor' => 'CUR1'])
            ->push(['data' => [directoryRecord('sub-2', 'Budi Two'), directoryRecord('sub-3', 'Budi Three')], 'next_cursor' => null]),
    ]);

    $users = app(DirectorySyncService::class)->users()->collect();

    expect($users)->toHaveCount(3)
        ->and($users->pluck('sub')->all())->toBe(['sub-1', 'sub-2', 'sub-3']);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'cursor=CUR1'));
});

it('dispatches an event per retrieved user', function (): void {
    config()->set('raccount-sso.directory.enabled', true);
    Event::fake([DirectoryUserRetrieved::class]);

    Http::fake([
        'account.test/oauth/token' => Http::response([
            'expires_in' => 3600,
            'access_token' => 'm2m-token',
        ]),
        'account.test/api/v1/directory/users*' => Http::response([
            'data' => [directoryRecord('sub-1', 'Budi One')],
            'next_cursor' => null,
        ]),
    ]);

    app(DirectorySyncService::class)->users()->collect();

    Event::assertDispatchedTimes(DirectoryUserRetrieved::class, 1);
});

it('caches the machine token between pages', function (): void {
    config()->set('raccount-sso.directory.enabled', true);

    Http::fake([
        'account.test/oauth/token' => Http::response([
            'expires_in' => 3600,
            'access_token' => 'm2m-token',
        ]),
        'account.test/api/v1/directory/users*' => Http::sequence()
            ->push(['data' => [directoryRecord('sub-1', 'Budi One')], 'next_cursor' => 'CUR1'])
            ->push(['data' => [], 'next_cursor' => null]),
    ]);

    app(DirectorySyncService::class)->users()->collect();

    // assertSentInOrder requires one matcher per recorded request, so the
    // initial cursorless page is matched alongside the token and cursor pages.
    Http::assertSentInOrder([
        fn ($request): bool => str_ends_with($request->url(), '/oauth/token'),
        fn ($request): bool => ! str_contains($request->url(), 'cursor='),
        fn ($request): bool => str_contains($request->url(), 'cursor=CUR1'),
    ]);

    expect(app(ClientCredentialsManager::class)->token())->toBe('m2m-token');
    Http::assertSentCount(3); // token + 2 pages, no second token request
});

it('flushes the cached machine token', function (): void {
    config()->set('raccount-sso.directory.enabled', true);

    Http::fake([
        'account.test/oauth/token' => Http::sequence()
            ->push(['expires_in' => 3600, 'access_token' => 'first'])
            ->push(['expires_in' => 3600, 'access_token' => 'second']),
    ]);

    $manager = app(ClientCredentialsManager::class);

    expect($manager->token())->toBe('first');

    $manager->flush();

    expect($manager->token())->toBe('second');
});
