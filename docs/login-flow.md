# Login / logout flow

This document walks through exactly what happens on the wire and in your database when a user logs
in with RAccount, what can go wrong, and how token refresh works afterwards.

## Sequence

```text
Browser                     Laravel app                          RAccount server
-------                     ------------                          ---------------
  |  GET /raccount/redirect        |                                     |
  |------------------------------->|                                     |
  |                                | generate state (32 random bytes)   |
  |                                | generate PKCE verifier + S256      |
  |                                | store both in the session          |
  |<-------------------------------|                                     |
  |  302 -> /oauth/authorize?response_type=code                       |
  |         &client_id&redirect_uri&scope=profile email                |
  |         &state&code_challenge&code_challenge_method=S256           |
  |----------------------------------------------------------------------->|
  |                                |                        user signs in |
  |                                |                        consent screen|
  |<-----------------------------------------------------------------------|
  |  302 -> https://your-app/raccount/callback?code=...&state=...        |
  |------------------------------->|                                     |
  |  GET /raccount/callback         |                                     |
  |                                | pull + forget state & verifier      |
  |                                | compare state (hash_equals)        |
  |                                | POST /oauth/token (code,           |
  |                                |      verifier, Basic auth)         |
  |                                |------------------------------------>|
  |                                |          access + refresh tokens   |
  |                                |<------------------------------------|
  |                                | GET /api/v1/userinfo (Bearer)      |
  |                                |------------------------------------>|
  |                                |          sub, name, email, ...     |
  |                                |<------------------------------------|
  |                                | resolver: link or provision user   |
  |                                | store tokens (encrypted)           |
  |                                | Auth::login + session regenerate   |
  |<-------------------------------|                                     |
  |  302 -> intended() or redirects.after_login                          |
```

## Routes

| Route | Name | Purpose |
| --- | --- | --- |
| `GET /raccount/redirect` | `raccount.login` | Starts the flow. Link your "Login with RAccount" button here (`<x-raccount::button />` does this for you). |
| `GET /raccount/callback` | `raccount.callback` | Handles the authorization server's redirect. |
| `GET /raccount/logout` | `raccount.logout` | Local logout + best-effort token revocation. Registered only when `routes.logout_enabled` is `true`. |

All three run inside the `web` middleware group (configurable via `routes.middleware`). The prefix
is configurable via `routes.prefix`.

## Session keys

| Key | Written by | Lifetime |
| --- | --- | --- |
| `raccount-sso.state` | redirect | Single use — pulled (deleted) by the callback |
| `raccount-sso.verifier` | redirect | Single use — pulled (deleted) by the callback |
| `raccount-sso.error` | callback / middleware | Flash — one request, for your login page to display |

Because both values are *pulled* on the callback request, a second visit to the callback URL (a
refresh, a replayed bookmark) can never complete the exchange — the state comparison fails and the
request aborts with **419 Page Expired**. This is the single-use guarantee for both the CSRF state
and the PKCE verifier.

## What happens at each step

1. **Redirect.** A 32-byte random hex `state` and a PKCE verifier (64 random bytes, base64url) are
   generated and stored in the session; the user is redirected to `/oauth/authorize` with the S256
   code challenge — the verifier itself never leaves the server.
2. **Callback, authorization error.** If RAccount redirects back with an `error` query parameter
   (for example `error=access_denied` when the user rejected consent, or `access_denied` when an
   admin revoked the client), the callback flashes `error_description` (or `error`) to the
   `raccount-sso.error` session key and redirects to the route named by
   `redirects.on_error`. No exception is thrown; show the flash message on your login page.
3. **Callback, state mismatch.** Missing/unknown `state`, a missing session verifier, or a
   non-matching comparison (constant-time via `hash_equals`) aborts with **419**. This is the CSRF
   defense for the flow.
4. **Token exchange.** The code + verifier are posted to `/oauth/token` with HTTP Basic client
   authentication. Transport failures (connection errors, 5xx) are retried with jittered backoff;
   `429` raises `RateLimited`; OAuth error responses raise typed exceptions
   (`TokenExchangeFailed`, `InvalidGrant`).
5. **Userinfo.** The access token is exchanged for claims at `/api/v1/userinfo`
   (`sub`, `name`, `picture`, `email`, `email_verified`, `updated_at`).
6. **Resolution.** The bound `UserResolver` (default: `DefaultUserResolver`) maps the identity to a
   local user inside a `lockForUpdate` transaction keyed on `raccount_sub`:
   - Existing active link → return the linked user, refresh the profile snapshot.
   - Existing link with status `suspended`/`deleted` → `AccountLinkageDenied`.
   - Stale link (local user deleted) → link row removed, continue as first login.
   - First login, email not verified (and `user.require_verified_email`) → `EmailNotVerified`.
   - First login, verified email matching an existing local user (case-insensitive on
     `user.email_column`) → auto-link when `user.auto_link_verified_email`, otherwise
     `AccountLinkageDenied`.
   - Otherwise → JIT-provision a local user from `user.attributes`.

   **`AccountLinkageDenied` (and its subclass `EmailNotVerified`) is handled gracefully**: the
   message is flashed to `raccount-sso.error` and the user is redirected to the route named by
   `redirects.on_error` — no 500, no leaked exception details.
7. **Login.** Tokens are persisted encrypted (see below), `Auth::login()` runs, the session ID is
   regenerated (session fixation defense), and the user lands on `redirect()->intended()` — i.e.
   the URL they originally tried to reach when redirected by `auth` middleware — falling back to
   the `redirects.after_login` path (`/home` by default).

## Logout

`GET /raccount/logout` (`raccount.logout`):

1. Looks up the user's `raccount_accounts` row.
2. Best-effort revokes the refresh token, then the access token, at `/oauth/revoke`. Failures
   (network, 4xx) are logged as warnings and swallowed — logout must never be blocked by the
   identity server being down.
3. Clears the locally stored tokens regardless of the revocation outcome.
4. Runs `Auth::logout()`, invalidates the session, and regenerates the CSRF token.
5. Redirects to the `redirects.after_logout` path (`/` by default).

There is **no RP-initiated single logout**: logging out of your application does not log the user
out of RAccount (their SSO session lives in the browser against the RAccount domain, which your
application cannot touch), and there is no front/back-channel logout channel in this SDK. The
security trade-offs, and the `middleware.enforce_status` + webhooks pattern for immediate local
kill-off, are discussed in [security.md](security.md#session--token-lifetime).

## Token storage

The callback persists a `RaccountAccount` row (keyed uniquely on `raccount_sub`, morph-linked to
your user): profile snapshot, granted scopes, `access_token`, `refresh_token`, and
`access_expires_at`. Both token columns use Laravel's `encrypted` cast — at rest they are
ciphertext keyed to `APP_KEY`. Access tokens live roughly 15 minutes (`expires_in` is 900s by
default); refresh tokens are single-use and rotate on every refresh.

## Refreshing tokens

Call `TokenService::refresh()` when you need a live access token for an API call:

```php
use Illuminate\Support\Facades\Auth;
use Raccount\Sso\Exceptions\InvalidGrant;
use Raccount\Sso\Models\RaccountAccount;
use Raccount\Sso\Tokens\TokenService;

/** @var RaccountAccount $account */
$account = RaccountAccount::query()->forUser($user)->firstOrFail();

try {
    $tokens = app(TokenService::class)->refresh($account);
    // $tokens->accessToken is now valid for ~15 minutes; the stored row
    // was updated with the rotated refresh token.
} catch (InvalidGrant $exception) {
    // The refresh-token family was revoked (reused, expired, or revoked
    // server-side). The stored tokens have been cleared.
    // Contract: log the user out and start a new authorization flow.
    // NEVER retry the same grant — it will fail forever.
    Auth::logout();
    return redirect()->route('raccount.login');
}
```

The `invalid_grant` → **logout + re-login** contract is absolute: RAccount rotates the refresh
token on every use and revokes the whole family on reuse, so an `invalid_grant` response is
permanent for that token lineage. `TokenService::refresh()` already clears the stored tokens
before rethrowing, so the next login starts clean. See also
[security.md](security.md#replay--reuse) for why retrying the grant is harmful.

## Error display on the login page

Every user-facing failure path funnels into one convention — a flashed `raccount-sso.error`
message plus a redirect to the route named by `redirects.on_error`. Display it wherever you render
your login form:

```blade
@if (session('raccount-sso.error'))
    <div class="alert alert-danger" role="alert">
        {{ session('raccount-sso.error') }}
    </div>
@endif

<x-raccount::button class="btn btn-primary" />
```
