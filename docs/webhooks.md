# Webhooks

RAccount pushes user lifecycle changes to your application with signed webhooks. This document
covers the wire format, the delivery/retry contract, how the package verifies and deduplicates
deliveries, and how to write listeners.

## Enabling webhooks

1. Ask the RAccount admin to configure a webhook endpoint pointing at your application:
   `https://your-app.example.com/raccount/webhook`.
2. Obtain a signing secret and set it in your environment:

```env
RACCOUNT_SSO_WEBHOOK_SECRET=whsec_...
```

3. Enable the feature in `config/raccount-sso.php` (or via the config file you published):

```php
'webhooks' => [
    'enabled' => true,
],
```

When enabled, the package registers `POST raccount/webhook` (path configurable via
`webhooks.path`) with `throttle:60,1` middleware. The route intentionally does **not** run in the
`web` group, so Laravel's CSRF verification does not apply — authenticity is provided by the HMAC
signature, not by a session token.

## Headers

| Header | Format | Purpose |
| --- | --- | --- |
| `X-RAccount-Signature` | `sha256=` + 64 lowercase hex chars | HMAC-SHA256 of the **raw request body** using the shared secret. |
| `X-RAccount-Event-Id` | ULID | Unique id of the event; the deduplication key. Echoed by the package as the primary key of the `raccount_webhook_events` claim row. |
| `X-RAccount-Event-Type` | `user.created`, `user.updated`, `user.suspended`, `user.reactivated`, `user.deleted` | Discriminates the typed Laravel event dispatched after verification. |
| `X-RAccount-Timestamp` | Parseable datetime | Claimed signing time; accepted within `webhooks.tolerance` (default ±300 seconds) of the receiver's clock. |

## Payload shape

```json
{
    "occurred_at": "2026-09-07T10:21:04Z",
    "actor": { "type": "admin", "id": "01JD2Z3W4..." },
    "data": {
        "sub": "b34c1a66-...",
        "name": "Ayu Lestari",
        "email": "ayu@example.com",
        "picture": "https://cdn.example.com/avatars/ayu.png"
    },
    "changed": ["email"]
}
```

All webhook events expose a `WebhookPayload` with:

- `->eventId`, `->type` — from the delivery headers,
- `->occurredAt` (`?CarbonImmutable`) — when the change happened on the server,
- `->actor` (`array{type?: string, id?: string}`) — who performed the change,
- `->data` (`array`) — the subject record; `->sub()` is a shortcut for the subject's stable id,
- `->changed` (`list<string>`) — which fields changed in this event.

## Verification

The receiver recomputes `sha256=` + `hash_hmac('sha256', $rawBody, $secret)` for every configured
secret and compares it against the header with `hash_equals` (constant time). A delivery fails
verification when the signature is missing/malformed, matches no configured secret, the timestamp
is missing/unparseable, or lies outside the tolerance window — and also when the event id is
empty. Failed verification is **final**: the package logs a warning and returns `200` so the
server stops retrying an undeliverable delivery.

Exactly which piece guards against replay — and why the timestamp alone must not be trusted — is
covered in [security.md](security.md#webhook-replay-protection).

## At-least-once delivery and idempotent listeners

The server may deliver the **same event more than once** (timeouts, retries, re-balancing). The
contract is *at-least-once delivery, exactly-once effect*: the package guarantees deduplication,
and your listeners must be **idempotent** — running twice must produce the same state as running
once. In practice: write state transitions (`updateOrCreate`, setting a status column) rather than
append-only side effects (sending an email, incrementing a counter) directly in the webhook
listener, or guard those side effects with your own deduplication.

The package enforces at-least-once semantics with **two-phase claim processing** on the
`raccount_webhook_events` table:

1. **Claim.** After verification, the row is looked up by event id (`received_at` set). If a claim
   already exists **with `processed_at` set**, the delivery is a duplicate of a fully-processed
   event and gets an immediate `200` without re-dispatching. If no claim exists, one is inserted
   (a concurrent duplicate insert loses the race and re-reads the winner).
2. **Process + mark.** `WebhookReceived` and the typed event are dispatched; when all listeners
   return without throwing, `processed_at` is set and the delivery is acknowledged with `200`.

   If any listener throws, `processed_at` is **not** set, the exception is reported to your
   application's handler, and the response is **`500`** — the marker of "not processed". The
   server will retry the delivery through its retry ladder, and the claim row (unprocessed) lets
   the next attempt run your listeners again.

## Response-code semantics

| Outcome | HTTP | Server behavior |
| --- | --- | --- |
| Signature/timestamp/event-id verification failed | `200` (silent) | Stops retrying — the delivery can never succeed. Details are in your application log. |
| Duplicate of an already-processed event | `200` | Done; nothing re-dispatched. |
| Processed successfully | `200` | Done. |
| A listener threw | `500` | Retries the delivery later (at-least-once). |

The corollary: a listener that fails intermittently will cause repeated 500s until it succeeds —
keep listeners fast, idempotent, and defensive (a missing local user is a normal condition, not an
exception).

## Events dispatched

| Event class | Trigger (`X-RAccount-Event-Type`) |
| --- | --- |
| `Raccount\Sso\Events\WebhookReceived` | Every verified delivery, including types with no dedicated event. |
| `Raccount\Sso\Events\UserCreated` | `user.created` |
| `Raccount\Sso\Events\UserUpdated` | `user.updated` |
| `Raccount\Sso\Events\UserSuspended` | `user.suspended` |
| `Raccount\Sso\Events\UserReactivated` | `user.reactivated` |
| `Raccount\Sso\Events\UserDeleted` | `user.deleted` |

## Built-in status listener

With `webhooks.listeners_enabled => true` (the default), the package registers
`Raccount\Sso\Listeners\UpdateAccountStatus` on the five user events. It looks up the
`raccount_accounts` row by `sub` and, if present, updates its `status`
(`user.suspended` → `suspended`, `user.deleted` → `deleted`, the other three → `active`) and
refreshes the `name` / `email` / `picture_url` snapshot when those keys appear in `data`. Rows
for unknown subjects are ignored silently. Combined with
`middleware.enforce_status => true`, this is what turns a server-side suspension into an
immediate local logout — see [security.md](security.md#session--token-lifetime).

Set `webhooks.listeners_enabled => false` if you want to own status handling entirely.

## Writing a listener

```php
<?php

namespace App\Listeners;

use Raccount\Sso\Events\UserSuspended;

final class RevokeSuspendedUserSessions
{
    public function handle(UserSuspended $event): void
    {
        $sub = $event->payload->sub();

        if ($sub === null) {
            return;
        }

        // Idempotent by construction: re-running this for a duplicate
        // delivery yields the same end state.
        // e.g. drop the user's sessions, queue a notification, ...
    }
}
```

Register it in `app/Providers/AppServiceProvider.php`:

```php
use Raccount\Sso\Events\UserSuspended;
use App\Listeners\RevokeSuspendedUserSessions;

public function boot(): void
{
    Event::listen(UserSuspended::class, RevokeSuspendedUserSessions::class);
}
```

The event object gives you `$event->payload->occurredAt`, `->actor`, `->data`, `->changed`,
`->eventId` and `->type` for auditing.

## Secret rotation

Rotate the webhook secret without dropping deliveries:

1. Ask the RAccount admin to generate a **new** secret for your endpoint (they will sign with it
   from a known point in time).
2. In your environment, set the new secret as the primary and demote the current one:

```env
RACCOUNT_SSO_WEBHOOK_SECRET=whsec_new...
RACCOUNT_SSO_WEBHOOK_SECRET_PREVIOUS=whsec_old...
```

   The verifier accepts a signature from **either** secret, so in-flight deliveries signed with the
   old secret still pass while the server transitions.
3. Once every in-flight delivery predates the rotation (the tolerance window plus the server's
   retry horizon — one hour is a safe rule of thumb), remove `_PREVIOUS` again.

The same procedure applies in emergencies: rotate server-side first (step 1) so the leaked secret
stops signing, then update both env vars, then clean up.

## Pruning the claim table

Claim rows are only needed for deduplication while the server might still retry an event. Prune
them on a schedule:

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('raccount:prune-webhooks --days=30')->daily();
```

`--days` defaults to `30`. Rows are pruned by `received_at`, regardless of `processed_at` — a
30-day horizon comfortably outlives the server's retry ladder, so you never prune an event the
server might still redeliver.
