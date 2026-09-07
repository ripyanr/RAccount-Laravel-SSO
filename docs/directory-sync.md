# Directory sync

Directory sync is the machine-to-machine (M2M) channel into the RAccount user directory. Where the
login flow is user-triggered and only touches identities that actually sign in, directory sync
lets your application enumerate **every** RAccount user on a schedule — for an initial backfill
before a migration cutover, and for incremental reconciliation afterwards.

## What it is for

- **Initial backfill** — provision local user rows for the whole directory before users ever log
  in, so they arrive to configured teams, permissions, and content on first login.
- **Incremental reconciliation** — re-walk only records that changed since a checkpoint
  (`updated_since`), keeping local profile data fresh even for users who never visit.

What it is *not* for: deletions. Soft-deleted users never appear in directory pages — deletion is
an event, not a state you can poll, and it arrives as a `user.deleted` webhook (see
[webhooks.md](webhooks.md)). Treat "absent from the directory" as unknown, not deleted.

## Prerequisites

1. A **client-credentials client** (separate from, or identical to, your login client — it must be
   confidential either way) with the `sync:read` scope, issued by the RAccount admin.
2. Directory enablement in your application:

```env
RACCOUNT_SSO_DIRECTORY_ENABLED=true
```

The client-credentials grant authenticates with the same `RACCOUNT_SSO_CLIENT_ID` /
`RACCOUNT_SSO_CLIENT_SECRET` env vars used by the login flow, posting
`grant_type=client_credentials&scope=sync:read` to the token endpoint.

## Configuration

| Key | Default | Description |
| --- | --- | --- |
| `directory.enabled` | `RACCOUNT_SSO_DIRECTORY_ENABLED` (`false`) | Feature flag for directory sync. |
| `directory.scope` | `sync:read` | Scope requested with the client-credentials grant. |
| `directory.cache_store` | `null` | Cache store holding the M2M token; `null` = the application default. Use a **shared** store (Redis, database, memcached) in multi-worker deployments so workers share one token instead of each minting its own. |
| `directory.page_limit` | `200` | Page size per directory request. |

The M2M access token is cached under the `raccount-sso:m2m-token` key with a TTL of the token's
expiry minus a 60-second safety margin (minimum 60 seconds), so token minting is amortized across
syncs. Tokens are cached in your cache store — never in process memory — which is one of the
reasons the package is Octane-safe (see [octane.md](octane.md)).

## How the walk works

`DirectorySyncService::users(?DateTimeInterface $updatedSince)` returns a lazy collection backed
by a generator:

1. Obtain an M2M token (from cache, or mint a fresh one).
2. Request `GET /api/v1/directory/users` with `limit`, optional `cursor`, and optional
   `updated_since` (ISO-8601).
3. For each record in the page, dispatch `Raccount\Sso\Events\DirectoryUserRetrieved`, then yield
   the `Raccount\Sso\Client\Dto\DirectoryUser`.
4. Follow `next_cursor` until the server stops returning one — pages are ordered by `updated_at`
   ascending, so the walk is stable and resumable via `--since`.

Because the collection is lazy, memory stays flat regardless of directory size: records are
processed one at a time, not buffered.

## Listening to records

```php
<?php

namespace App\Listeners;

use Raccount\Sso\Events\DirectoryUserRetrieved;

final class UpsertDirectoryUser
{
    public function handle(DirectoryUserRetrieved $event): void
    {
        $record = $event->user;

        // $record->sub            stable RAccount id (string)
        // $record->name           display name
        // $record->email          email address
        // $record->emailVerified  bool
        // $record->status         'active' | 'suspended' | ...
        // $record->updatedAt      CarbonImmutable

        // Idempotent upsert keyed on the stable sub — safe under re-delivery
        // and repeated syncs:
        $user = \App\Models\User::updateOrCreate(
            ['email' => $record->email],
            ['name' => $record->name],
        );

        \Raccount\Sso\Models\RaccountAccount::updateOrCreate(
            ['raccount_sub' => $record->sub],
            [
                'user_type' => $user->getMorphClass(),
                'user_id' => $user->getKey(),
                'email' => $record->email,
                'name' => $record->name,
                'status' => $record->status,
            ],
        );
    }
}
```

Register it in `app/Providers/AppServiceProvider.php`:

```php
use Raccount\Sso\Events\DirectoryUserRetrieved;
use App\Listeners\UpsertDirectoryUser;

public function boot(): void
{
    Event::listen(DirectoryUserRetrieved::class, UpsertDirectoryUser::class);
}
```

Your listener runs inside the walk, so keep it fast; enqueue real work (emails, integrations)
onto a queue.

## Running a sync

```bash
# Full walk of the directory
php artisan raccount:directory:sync

# Only records updated at or after a checkpoint (ISO-8601)
php artisan raccount:directory:sync --since=2026-09-01T00:00:00Z
```

The command reports how many records were processed and how long the walk took. It exits non-zero
when the directory could not be fetched (connectivity, credentials, rate limiting) or when
`--since` is not a valid ISO-8601 timestamp.

For incremental reconciliation on a schedule, use an overlapping fixed window rather than a
precise checkpoint — it is simpler and equally correct:

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

// Runs hourly, each time re-walking everything touched in the last 2 hours.
// The overlap is intentional and harmless because processing is idempotent;
// a gap (under-covered window) is what would lose records.
Schedule::command('raccount:directory:sync --since='.(now()->subHours(2)->toIso8601ZuluString()))
    ->hourly();
```

Alternatively, persist a real checkpoint (e.g. the newest `updated_at` you have seen) in a cache
key or settings row and feed it back as `--since` from a small invokable class registered with
`Schedule::call()`; the property that matters is the same either way — the covered interval must
overlap the previous run, because ordering is by `updated_at` ascending with cursor pagination
and overlapping windows are safe while gaps are not.

## Service usage without the command

You can drive the walk yourself if you want custom pagination or progress reporting:

```php
use Raccount\Sso\Directory\DirectorySyncService;

$records = app(DirectorySyncService::class)->users(updatedSince: $checkpoint);

$records->each(function (\Raccount\Sso\Client\Dto\DirectoryUser $record): void {
    // The DirectoryUserRetrieved event has already fired for this record.
});
```
