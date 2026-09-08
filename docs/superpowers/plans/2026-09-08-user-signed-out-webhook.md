# `user.signed_out` Webhook (Back-Channel Logout) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dispatch a typed `UserSignedOut` Laravel event when the RAccount SSO server delivers a `user.signed_out` webhook, and document back-channel logout.

**Architecture:** Mirror the existing five-event pattern exactly: one empty final event class extending `UserEvent`, one match arm in `WebhookPayload::eventClassFor()`. The controller's verify → claim → dispatch → mark pipeline is already type-agnostic. The built-in `UpdateAccountStatus` listener is deliberately **not** registered for this event (sign-out is not a status change); apps end their own local sessions via an app-owned listener documented with an example.

**Tech Stack:** PHP 8.x / Laravel 13 package, Pest 4 + testbench 11, PHPStan (larastan) level 6, Pint.

**Spec:** `docs/superpowers/specs/2026-09-08-user-signed-out-webhook-design.md`

## Global Constraints

- Wire contract is fixed by the server: header `X-RAccount-Event-Type: user.signed_out`; payload is the standard claims snapshot (real example in the spec; fixture must copy it verbatim except `data.sub`, replaced with the suite's fake sub `0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0`).
- No changes to config, routes, migrations, commands, or the built-in listener's registration list.
- Existing five events' behavior must not change.
- Test commands: `vendor/bin/pest`, `vendor/bin/phpstan analyse`, `vendor/bin/pint` (repo root). All must be green at the end.

---

### Task 1: `UserSignedOut` event + dispatch mapping (TDD)

**Files:**
- Modify: `tests/Feature/WebhookTest.php` (add import + two tests after the `refreshes the snapshot…` test, before the `returns 500…` test)
- Create: `src/Events/UserSignedOut.php`
- Modify: `src/Webhooks/WebhookPayload.php` (import + match arm)

**Interfaces:**
- Consumes: `postWebhook(array $body, ...)` helper already in `WebhookTest.php` (signs the body, sets headers from `$body['id']`/`$body['type']`); `RaccountAccount::STATUS_*`, `morphTypeFor()`; fixture `Raccount\Sso\Tests\Fixtures\User`.
- Produces: `Raccount\Sso\Events\UserSignedOut extends UserEvent` (constructor inherited: `public readonly WebhookPayload $payload`); `WebhookPayload::eventClassFor('user.signed_out')` returns `UserSignedOut::class`.

- [ ] **Step 1: Write the failing tests**

Add to the imports at the top of `tests/Feature/WebhookTest.php`:

```php
use Raccount\Sso\Events\UserSignedOut;
```

Add these two tests (fixture = the production payload from the spec, fake sub):

```php
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/WebhookTest.php`
Expected: 2 FAILURES — first test: `UserSignedOut` class does not exist (Error); second test: fails at `assertOk()`… (actually also class-not-found on the `Event::fake`/import). If PHP fatal-errors on the missing class before running, that still proves red.

- [ ] **Step 3: Write minimal implementation**

Create `src/Events/UserSignedOut.php`:

```php
<?php

namespace Raccount\Sso\Events;

final class UserSignedOut extends UserEvent {}
```

In `src/Webhooks/WebhookPayload.php`, add the import (alphabetical position after `UserSignedOut` sorts after `UserSuspended`? — order: UserCreated, UserDeleted, UserEvent, UserReactivated, UserSignedOut, UserSuspended, UserUpdated — wait, alphabetically `UserSignedOut` < `UserSuspended` because 'i' < 'u' at position 5: `UserSi...` vs `UserSu...`; correct spot is between `UserReactivated` and `UserSuspended`):

```php
use Raccount\Sso\Events\UserSignedOut;
```

and the match arm between `user.reactivated` and `user.deleted` (keep list in event-lifecycle order; append after `user.deleted` is also acceptable — choose after `user.deleted` to keep the five lifecycle events grouped):

```php
'user.signed_out' => UserSignedOut::class,
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/WebhookTest.php`
Expected: PASS (all, including the 2 new ones).

- [ ] **Step 5: Commit**

```bash
git add src/Events/UserSignedOut.php src/Webhooks/WebhookPayload.php tests/Feature/WebhookTest.php
git commit -m "feat: dispatch UserSignedOut for the user.signed_out webhook"
```

---

### Task 2: Documentation + changelog

**Files:**
- Modify: `docs/webhooks.md` (4 edits below)
- Modify: `docs/security.md` (1 edit)
- Modify: `README.md` (1 edit)
- Modify: `CHANGELOG.md` (new Unreleased section)

**Interfaces:**
- Consumes: Task 1's `UserSignedOut` class name and the `user.signed_out` wire type.
- Produces: none (docs only).

- [ ] **Step 1: `docs/webhooks.md` headers table (line 36)**

Change the `X-RAccount-Event-Type` row's Format cell from
``user.created`, `user.updated`, `user.suspended`, `user.reactivated`, `user.deleted`` to
``user.created`, `user.updated`, `user.suspended`, `user.reactivated`, `user.deleted`, `user.signed_out``.

- [ ] **Step 2: `docs/webhooks.md` events-dispatched table + built-in listener wording**

After the `UserDeleted` row (line 121) add:

```markdown
| `Raccount\Sso\Events\UserSignedOut` | `user.signed_out` |
```

In the "Built-in status listener" section, change "the package registers
`Raccount\Sso\Listeners\UpdateAccountStatus` on the five user events" to "…on the five
lifecycle user events" and append to that paragraph's end (after "Rows for unknown subjects are ignored silently."):

```markdown
`user.signed_out` is deliberately not registered with the built-in listener — ending the
RAccount session is not an account-status change — so the `raccount_accounts` row is left
untouched; see [Back-channel logout](#back-channel-logout-usersigned_out) below.
```

- [ ] **Step 3: `docs/webhooks.md` new section**

Insert a new `## Back-channel logout (user.signed_out)` section between "Built-in status listener" and "Writing a listener":

````markdown
## Back-channel logout (`user.signed_out`)

When a user ends their RAccount session through an explicit sign-out at the SSO server
(POST /logout there), the server delivers `user.signed_out` so your application can end
its own local sessions for that user — back-channel logout. Delivery mechanics are
identical to the other events (same headers, HMAC signature, tolerance window, retry
ladder, and event-id deduplication), and the payload is the standard claims snapshot;
`data.sub` identifies who signed out.

Ending your local sessions is application-specific — the package dispatches the event and
leaves the rest to you. For the `database` session driver:

```php
<?php

namespace App\Listeners;

use Illuminate\Support\Facades\DB;
use Raccount\Sso\Events\UserSignedOut;
use Raccount\Sso\Models\RaccountAccount;

final class EndLocalSessionsOnSsoSignOut
{
    public function handle(UserSignedOut $event): void
    {
        $sub = $event->payload->sub();

        if ($sub === null) {
            return;
        }

        $account = RaccountAccount::query()->where('raccount_sub', $sub)->first();

        if ($account === null) {
            return; // unknown subject — nothing to end
        }

        // Idempotent by construction: deleting rows that may already be
        // gone yields the same end state on a redelivered event.
        DB::table('sessions')->where('user_id', $account->user_id)->delete();
    }
}
```

Register it as shown in [Writing a listener](#writing-a-listener), with
`Event::listen(UserSignedOut::class, EndLocalSessionsOnSsoSignOut::class)`. Adapt the
last line to your stack — revoke Sanctum tokens instead, or no-op entirely on the `file`
driver (where other users' sessions cannot be targeted) and rely on short-lived access
tokens plus `middleware.enforce_status` instead.
````

- [ ] **Step 4: `docs/security.md` Session & token lifetime**

After the paragraph ending "…the webhook flips the status, the middleware enforces it on the
next request, and the already-issued access token ages out within minutes." (line 66) add:

```markdown
The reverse direction — a user signing out of their RAccount session directly at the SSO
server — is closed by the `user.signed_out` webhook: the sign-out propagates to your
application as a signed delivery, so a listener can end the user's local session
immediately instead of waiting for the access token to age out. See
[webhooks.md](webhooks.md#back-channel-logout-usersigned_out).
```

- [ ] **Step 5: `README.md` feature bullet**

Change "Laravel events for user created/updated/suspended/reactivated/deleted." to "Laravel
events for user created/updated/suspended/reactivated/deleted, plus `user.signed_out`
(back-channel logout)."

- [ ] **Step 6: `CHANGELOG.md`**

Insert a new section directly after the Keep-a-Changelog preamble (before `## [1.0.0]`):

```markdown
## [Unreleased]

### Added

- Support for the `user.signed_out` webhook (back-channel logout): verified deliveries
  dispatch `Raccount\Sso\Events\UserSignedOut`. The built-in `UpdateAccountStatus` listener
  deliberately ignores the event — signing out of the RAccount session is not an
  account-status change — so register your own listener to end local sessions (see
  `docs/webhooks.md`).
```

- [ ] **Step 7: Commit**

```bash
git add docs/webhooks.md docs/security.md README.md CHANGELOG.md
git commit -m "docs: document the user.signed_out back-channel logout webhook"
```

---

### Task 3: Full verification

**Files:** none (verification only).

- [ ] **Step 1: Full suite**

Run: `vendor/bin/pest`
Expected: 93 passed (91 baseline + 2 new).

- [ ] **Step 2: Static analysis**

Run: `vendor/bin/phpstan analyse`
Expected: 0 errors (the new tests reuse patterns already covered by existing ignores for `tests/Feature/WebhookTest.php`).

- [ ] **Step 3: Code style**

Run: `vendor/bin/pint --test` then, if it fails, `vendor/bin/pint` and re-run tests.
Expected: clean / pass.

- [ ] **Step 4: Docs consistency sweep**

Run: `grep -rn 'user\.signed_out\|UserSignedOut' src docs README.md CHANGELOG.md tests | grep -v superpowers`
Expected: every listing is intentional; no doc still says the SDK handles only five events.

---

## Self-Review

- **Spec coverage:** typed event (Task 1), map entry (Task 1), no built-in listener + proof test (Task 1 test 2), webhooks.md tables + section (Task 2), security.md (Task 2), README + CHANGELOG (Task 2), fixture = real payload with fake sub (Task 1 Step 1). Config/routes/migrations untouched — matches spec.
- **Placeholders:** none; all doc content and code included verbatim.
- **Type consistency:** `UserSignedOut`, `user.signed_out`, fake sub `0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0`, event id `01M1ZK8X9CPDFN51CBZH2B7CM0` used consistently; import-order note in Task 1 Step 3 resolves to between `UserReactivated` and `UserSuspended`.
