# Security model

This document explains what the package defends against, how, and — just as importantly — what it
deliberately does not promise. Read it before putting the package in front of real users.

## Threat model

| Threat | Mitigation | Where |
| --- | --- | --- |
| CSRF on the authorization response (attacker forges or replays a callback) | Cryptographically random 32-byte `state`, stored server-side in the session, compared with `hash_equals`, and **single-use** (pulled from the session on first callback) | `/raccount/callback`; a mismatch aborts with 419 |
| Authorization-code interception (network attacker or malicious browser extension steals the code) | PKCE with the S256 method: the 64-byte verifier never leaves the server; the code is worthless without it and the verifier is single-use | Redirect + token exchange |
| MITM on the backchannel | Server base URL must be `https://` (plain HTTP only with `APP_ENV=local` **and** `server.allow_insecure`); client secret and Basic auth travel only over that channel | `RaccountClient` URL builder |
| Tokens exposed to the browser | Tokens live exclusively server-side: exchanged, stored, refreshed, and revoked from Laravel; nothing token-shaped is ever set in a cookie or returned to the client | Flow controllers + `TokenService` |
| Webhook forgery | HMAC-SHA256 over the raw body with a shared secret, constant-time comparison against every configured secret (rotation-aware) | `WebhookVerifier` |
| Webhook replay | Event-id deduplication on the `raccount_webhook_events` claim table — see [Webhook replay protection](#webhook-replay-protection) below for why this, and not the timestamp window, is the authoritative guard | `WebhookController` |
| Stolen/reused refresh token | Refresh tokens rotate on every use; the server revokes the whole family on reuse; an `invalid_grant` clears local tokens and forces re-login — it is never retried | `TokenService::refresh()` |
| Replayed authorization code | Codes are single-use server-side and bound to the (single-use) PKCE verifier; replay fails the exchange | Token endpoint semantics |
| Token/user data at rest (database leak) | `access_token` and `refresh_token` columns use Laravel's `encrypted` cast — ciphertext keyed to `APP_KEY` | `RaccountAccount` casts |
| Secret leakage through config or logs | Secrets only ever come from env vars / config; the HTTP client logs nothing; webhook rejection logging records only the event id | Config + client |
| Open redirect via the callback | Redirect URIs are registered server-side and matched exactly; the post-login redirect is `redirect()->intended()` — it only ever goes to a URL Laravel itself stashed when kicking the user to login, never to arbitrary query input | Callback + Laravel's intended mechanism |

## Webhook replay protection

The server's signing scheme covers **only the raw request body**: the `X-RAccount-Timestamp` and
`X-RAccount-Event-Id` headers are *not* part of the MAC. Concretely, an attacker who captured a
legitimate delivery could rewrite the timestamp header without invalidating the signature.

Therefore:

- The ±5-minute timestamp window (`webhooks.tolerance`) is **advisory only**. It rejects stale
  deliveries and keeps noisy garbage out, but it is not a security boundary — a captured delivery
  can be replayed with a fresh-looking timestamp header at any later time.
- The **authoritative replay guard is the `X-RAccount-Event-Id` deduplication table**. Every
  verified delivery claims its event id as the primary key of `raccount_webhook_events` before any
  listener runs, and only claims that reach `processed_at` are acknowledged; a replay of an
  already-processed event id returns `200` without re-dispatching. A replayed delivery with a
  *forged new* event id fails signature verification, because the attacker cannot produce a valid
  HMAC for a body they did not capture signed.

Two operational consequences follow. First, **do not truncate the claim table faster than the
server's retry horizon** — `raccount:prune-webhooks` defaults to 30 days for exactly this reason.
Second, protect the integrity of the claim table itself: it is only as trustworthy as your
database.

## Session & token lifetime

**Access tokens remain valid until they expire (~15 minutes) even after the user logs out of the
RAccount SSO session**, and there is no RP-initiated logout: your application cannot end the
browser's session against the RAccount domain, and a token issued before logout keeps working
against RAccount's APIs until its `exp`. `raccount.logout` mitigates this with **best-effort
revocation** of the stored access and refresh tokens — when the server is reachable, the access
token dies immediately; when it is not, the local copies are still destroyed and revocation simply
fails soft.

Applications that need a harder kill-off — suspension or compromise response measured in seconds,
not minutes — should combine all three layers:

1. **`raccount.logout`** (revocation) — kills the *token* grant.
2. **`middleware.enforce_status => true`** with the `raccount.active` middleware — kills the
   *local session* on the user's very next request, because the middleware checks the linked
   account's status row, not the token.
3. **Webhooks** with the built-in `UpdateAccountStatus` listener — propagates the server-side
   suspension/deletion into that status row without waiting for anyone to log in again.

Together these close the loop: the webhook flips the status, the middleware enforces it on the
next request, and the already-issued access token ages out within minutes.

## Replay & reuse: the `invalid_grant` contract

RAccount rotates the refresh token on every use and revokes the entire family when a rotated
token is presented again. So `invalid_grant` on a refresh means one of: the family was revoked
(user logged out elsewhere, admin revoked the grant, suspected theft), the token expired, or a
stolen token was replayed and the family was burned as a result. In every case the correct
response is identical, and the SDK encodes it: **clear local tokens, log the user out, start a new
authorization flow**. Retrying the grant is never correct — the failure is deterministic, which is
why the HTTP client retries connection errors and 5xx but never 4xx.

## No discovery, no JWKS

The package intentionally does not implement `.well-known/openid-configuration` discovery or
JWKS-based ID-token validation. RAccount's contract for this SDK is server-side validation
instead: the authorization code is exchanged server-to-server (client secret over Basic auth), and
claims are fetched from `/api/v1/userinfo` with the access token — not decoded from a client-
verifiable JWT. Token validity questions that would otherwise be answered by local signature
checks are answered by `/oauth/introspect`. The practical trade-off: one extra round-trip per
validation, and no third-party JavaScript ever needs to verify a signature.

## Operational notes

- **Secrets hygiene.** `RACCOUNT_SSO_CLIENT_SECRET` and `RACCOUNT_SSO_WEBHOOK_SECRET` are the
  crown jewels; keep them in your secret manager, never in code review, and rotate the webhook
  secret with the [rotation procedure](webhooks.md#secret-rotation) so no delivery is dropped.
- **APP_KEY.** The encrypted token casts bind ciphertext to `APP_KEY`. Rotating `APP_KEY` renders
  stored tokens undecryptable (users must re-login) — plan rotations accordingly.
- **Transport.** Everything the package sends or receives — codes, secrets, tokens, claims,
  webhook bodies — is expected to travel over TLS; the package refuses plain HTTP outside local
  development.
- **Reporting.** Suspected vulnerabilities: see the Security section of the README — contact the
  maintainers directly, do not open a public issue.
