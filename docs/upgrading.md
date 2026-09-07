# Upgrading

## Current version

The current release line is **1.0.0** (`raccount/laravel-sso`). See [CHANGELOG.md](../CHANGELOG.md)
for the complete, dated history of changes.

## Upgrade policy

- The package follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html). In brief:
  - **Patch** (`1.0.x`) — bug fixes and internal improvements; drop-in replacement.
  - **Minor** (`1.x`) — backwards-compatible additions: new config keys, new events, new command
    options. Existing behavior and public APIs are unchanged.
  - **Major** (`x.0`) — breaking changes: removed/renamed config keys, changed route names or
    payload shapes, raised minimum PHP/Laravel versions.
- **Config keys are only ever added, and always with safe defaults.** After upgrading, re-run
  `php artisan vendor:publish --tag=raccount-sso-config --force` (or merge by hand) to pick up new
  keys — the package's merged defaults keep old installations working even without republishing.
- **Migrations are additive within a major version.** After upgrading, run
  `php artisan vendor:publish --tag=raccount-sso-migrations` and `php artisan migrate` to pick up
  any new tables or columns.
- **Event class names, method signatures on `RaccountClient` / `TokenService`, and route names**
  (`raccount.login`, `raccount.callback`, `raccount.logout`) are treated as public API; they
  change only in a major release and will be called out here.

## General upgrade steps

1. Read the relevant sections of the [changelog](../CHANGELOG.md) for the version(s) you are
   crossing.
2. `composer update raccount/laravel-sso`.
3. Republish and review config and migrations (see above), then `php artisan migrate`.
4. Run `php artisan raccount:check` — it verifies configuration, database tables, and server
   connectivity, and prints a per-check hint for anything that regressed.
5. Run your test suite and exercise a full login → logout cycle in staging before deploying.

## Upgrade policy for security fixes

Security fixes may be released as patches on the current and immediately previous minor lines,
bypassing the normal cadence. Subscribe to releases on GitHub to hear about them; the reporting
process is described in [security.md](security.md) and the README.
