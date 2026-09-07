# Changelog

All notable changes to `raccount/laravel-sso` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.0.0 - 2026-09-07

### Added

- Initial release.
- OAuth 2.0 authorization-code + PKCE (S256) login flow with CSRF `state`, registered at
  `GET /raccount/redirect`, `GET /raccount/callback`, and `GET /raccount/logout`.
- Configurable user resolution: JIT provisioning, verified-email auto-linking (case-insensitive),
  custom resolver contract (`Raccount\Sso\Contracts\UserResolver`).
- Token lifecycle: encrypted at-rest storage in `raccount_accounts`, refresh rotation with
  `invalid_grant` family revocation handling, best-effort revocation on logout.
- Signed webhooks (HMAC-SHA256) with replay protection (timestamp window + event-id dedupe table),
  `WebhookReceived` and per-type user events, built-in account status listener, and
  `raccount:prune-webhooks` command.
- Machine-to-machine directory sync with cached client-credentials token, cursor pagination,
  `DirectoryUserRetrieved` event stream, and `raccount:directory:sync {--since=}` command.
- Middleware aliases `raccount.active` (account status enforcement) and `raccount.exclusive`
  (local auth route takeover), plus exclusive-mode configuration.
- `raccount:check` diagnostics command covering configuration, database, and connectivity.
- `<x-raccount::button />` Blade component with publishable views
  (`raccount-sso-views` tag).
- Full documentation set under `docs/`.
