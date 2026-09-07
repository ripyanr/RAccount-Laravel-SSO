# Octane notes

`raccount/laravel-sso` is written to be safe under Laravel Octane, where a single application
instance serves many requests across long-lived workers and anything process-global leaks between
tenants of that process. Nothing in this package requires special Octane configuration; this
document explains *why*, and lists the two things that remain the host application's
responsibility.

## Why the package is Octane-safe

- **No static mutable state.** The SDK keeps nothing in static properties. All state lives in
  objects resolved from the container.
- **Config is read per call.** `RaccountClient` reads configuration (`raccount-sso.*`, timeouts,
  base URL, credentials) on every request it builds, so a `config()->set(...)` in a test or an
  admin panel takes effect immediately — there is no snapshotted-at-boot copy to go stale across
  worker ticks.
- **The `raccount-sso.client` singleton is stateless.** It wraps Laravel's stateless HTTP client
  factory; it accumulates no per-request data, so sharing it across requests on a worker is safe.
- **Machine tokens live in your cache store, not process memory.** The directory-sync M2M token is
  cached under `raccount-sso:m2m-token` in the configured `directory.cache_store` (default: your
  application's default store), with a TTL derived from the token's expiry. All workers share one
  token; no worker-private copies exist to leak or diverge.
- **Session and auth state flow through Laravel.** The `raccount-sso.state` / `raccount-sso.verifier`
  session keys, the flash messages, and the authenticated user all live in Laravel's session
  store — flushed and reloaded per request exactly as Octane expects.
- **The webhook claim table is shared, durable state.** Event-id deduplication runs through the
  database (`raccount_webhook_events`), so concurrent deliveries hitting different workers are
  still deduplicated correctly; the unique primary key plus the catch-and-re-read on
  `UniqueConstraintViolationException` make the claim race-safe.

## Route caching

Routes are registered in the service provider's `boot()` behind a `routesAreCached()` guard: when
`route:cache` has been run, the provider skips live registration entirely and Laravel loads the
cached route file instead. The flow routes (`raccount.login`, `raccount.callback`,
`raccount.logout`) and the webhook route all appear in the cached file with their config-resolved
prefix/path/middleware, so **`route:cache` is fully supported** — re-run it after changing
`routes.*` or `webhooks.path` values.

Likewise, `config:cache` is safe: the package config contains no closures (Octane and config
caching both var_export the file — closures would fatal), so the merged config serializes
cleanly.

## Host application responsibilities

1. **Register `raccount.active` inside the `web` group (or another session-backed stack).** The
   `raccount.active` middleware (`EnsureRaccountAccountActive`) logs the user out, invalidates the
   session, and regenerates the CSRF token when it rejects a request — all of which require a
   session store. If you attach it to a route without session middleware, it cannot do any of
   that. The usual arrangement (append it to the `web` group, after the session middleware)
   satisfies this by construction. The package's own flow routes already run in `web`.

2. **Use a shared session and cache store.** This is standard Octane advice, restated because
   this package leans on both: file-based sessions and an array/null cache are process- or
   request-scoped and break correctness under Octane (and under any multi-server deployment).
   Prefer Redis or database-backed sessions, and point `directory.cache_store` at a shared store
   too.

## Sanity checklist

```bash
php artisan route:cache   # works; includes the raccount routes
php artisan config:cache  # works; no closures in raccount-sso config
php artisan octane:start  # no special hot-reload or sandbox concerns from this package
```
