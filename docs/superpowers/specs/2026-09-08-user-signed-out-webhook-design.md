# `user.signed_out` webhook (back-channel logout) — SDK support

Date: 2026-09-08
Status: approved (autonomous session; spec driven by the server-side contract from the task brief)

## Context

The RAccount SSO server (GA session 22, decision D102) now emits a sixth webhook event,
`user.signed_out`, whenever a user ends their RAccount session via explicit sign-out
(POST /logout at the SSO server). The production endpoint at reducates.com is already
subscribed (live delivery `01M1ZK8X9CPDFN51CBZH2B7CM0`, attempt 1, HTTP 200).

The wire contract is identical to the existing five events: HMAC-SHA256 signed body,
headers `X-RAccount-Event-Id` (idempotency key), `X-RAccount-Delivery-Id`,
`X-RAccount-Event-Type: user.signed_out`, `X-RAccount-Timestamp` (±5 min window),
retry ladder 30 s → 6 h on non-2xx. The payload is the standard claims snapshot:

```json
{
    "id": "01M1ZK8X9CPDFN51CBZH2B7CM0",
    "type": "user.signed_out",
    "occurred_at": "2026-09-08T04:08:59Z",
    "actor": { "type": "admin", "id": "01a07bdd-b568-70fe-8f90-63adaf2381a4" },
    "data": {
        "sub": "01a07bdd-b568-70fe-8f90-63adaf2381a4",
        "name": "Reducates Admin",
        "email": "superadmin@reducates.com",
        "email_verified": true,
        "status": "active",
        "updated_at": "2026-09-08T03:57:14Z"
    }
}
```

## Goal

First-party client apps can end their own local sessions for a user when that user signs
out of RAccount — back-channel logout. The SDK's job is to verify, deduplicate, and
dispatch a typed Laravel event, exactly as it does for the five lifecycle events.

## Decision

Mirror the existing per-type event pattern; do **not** wire the event into the built-in
`UpdateAccountStatus` listener.

1. New typed event `Raccount\Sso\Events\UserSignedOut` (empty final class extending
   `UserEvent`, like the other five).
2. `WebhookPayload::eventClassFor()` maps `'user.signed_out' => UserSignedOut::class`.
   That single map entry is all the controller needs to verify → claim → dispatch → mark
   processed; delivery mechanics (signature, tolerance, dedup, retry semantics,
   `WebhookReceived`) are already generic.
3. `UpdateAccountStatus` keeps listening to the five lifecycle events only. Rationale:
   signing out of the SSO session is not an account-status change — the account stays
   `active` (the payload's own `data.status` confirms this) — and the snapshot refresh
   adds nothing that `user.updated` doesn't already provide. Wiring it in would cause a
   pointless write on every sign-out and muddy the listener's documented contract.
   Ending local sessions is app-specific (session driver, guards, tokens), so it belongs
   in an app-owned listener; the docs ship a concrete example.
4. No config, route, migration, or command changes.

## Alternatives considered

- Register `UpdateAccountStatus` on `UserSignedOut` for snapshot refresh only — rejected
  (see rationale above).
- Ship a built-in session-revoking listener (e.g., delete `sessions` rows for the linked
  user) — rejected: it would assume the database session driver and a specific user-id
  layout; wrong for file/redis drivers and Sanctum-style apps. YAGNI; the docs example
  shows the two lines an app needs.

## Touch points

- `src/Events/UserSignedOut.php` (new)
- `src/Webhooks/WebhookPayload.php` (map entry + import)
- `docs/webhooks.md` (headers table, events-dispatched table, new back-channel logout
  section with a listener example, built-in-listener wording "five lifecycle events")
- `docs/security.md` (Session & token lifetime: the reverse gap — server-side sign-out
  now reaching the app — is closed by `user.signed_out`)
- `README.md` (feature bullets)
- `CHANGELOG.md` (Unreleased → Added)
- `tests/Feature/WebhookTest.php` (new tests; fixture = the real payload above with a
  fake `sub`)

## Testing

TDD; fixture uses the production payload shape verbatim with a fake `sub`
(`0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0`, the constant the suite already uses):

1. A signed `user.signed_out` delivery returns 200, records a processed claim row, and
   dispatches `WebhookReceived` + `UserSignedOut` carrying the payload (`sub()`,
   `occurredAt`, actor).
2. The linked `raccount_accounts` row is untouched by `user.signed_out`: status stays
   `active`, snapshot (`name`) is not refreshed — proving the built-in listener is not
   registered for it.

Success criteria: `vendor/bin/pest` and `vendor/bin/phpstan analyse` green; docs render
consistently (six event types everywhere the five were listed); no behavior change for
the existing five events.
