# raccount/laravel-sso

[![run-tests](https://github.com/ripyanr/RAccount-Laravel-SSO/actions/workflows/run-tests.yml/badge.svg)](https://github.com/ripyanr/RAccount-Laravel-SSO/actions/workflows/run-tests.yml)
[![Packagist](https://img.shields.io/packagist/v/raccount/laravel-sso.svg)](https://packagist.org/packages/raccount/laravel-sso)

Single Sign-On client SDK for [RAccount](https://account.reducates.com) — the Reducates ecosystem
identity provider. Implements the full OAuth 2.0 authorization-code + PKCE flow, refresh-token
rotation, signed webhooks, and the M2M directory lookup for Laravel 13 applications.

## Features

- **Login flow**: redirect → callback → local session login, with CSRF `state` and PKCE S256 enforced.
- **User resolution**: links or provisions local users keyed on the stable `sub` claim; auto-links
  verified emails; fully customizable resolver.
- **Token lifecycle**: encrypted at-rest storage, rotation-aware refresh (`invalid_grant` = logout,
  never retry), best-effort revocation on logout.
- **Webhooks**: HMAC-SHA256 signature verification, ±5 min replay window, event-id deduplication,
  Laravel events for user created/updated/suspended/reactivated/deleted.
- **Directory sync**: client-credentials M2M token with caching, cursor pagination,
  `DirectoryUserRetrieved` event stream, `raccount:directory:sync` command.
- **Ops**: `raccount:check` diagnostics command, status middleware, exclusive-SSO middleware.
- **Octane-ready**: no static state; tokens in encrypted columns; machine tokens in your cache store.

## Requirements

- PHP 8.3+
- Laravel 13
- A confidential RAccount client (client id/secret + registered redirect URI)

## Installation

```bash
composer require raccount/laravel-sso
php artisan vendor:publish --tag=raccount-sso-config
php artisan vendor:publish --tag=raccount-sso-migrations
php artisan migrate
```

The service provider and `Raccount` facade are auto-discovered.

## Quickstart

1. Ask the RAccount admin to register your application (name, logo, redirect URI
   `https://your-app/raccount/callback`, scopes `profile email`, confidential client).
2. Configure your environment:

```env
RACCOUNT_SSO_SERVER_URL=https://account.reducates.com
RACCOUNT_SSO_CLIENT_ID=your-client-id
RACCOUNT_SSO_CLIENT_SECRET=your-client-secret
RACCOUNT_SSO_REDIRECT_URI=https://your-app/raccount/callback
```

3. Add a login button to your login page:

```blade
<x-raccount::button class="btn btn-primary" />
```

4. (Optional) force SSO-only authentication by appending the middleware to your `web` group:

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->web(append: [
        \Raccount\Sso\Http\Middleware\RedirectAuthRoutesToSso::class,
        \Raccount\Sso\Http\Middleware\EnsureRaccountAccountActive::class,
    ]);
})
```

and enable enforcement in `config/raccount-sso.php` (there is no env var for this key):

```php
'middleware' => ['enforce_status' => true],
```

5. Verify your setup:

```bash
php artisan raccount:check
```

## Documentation

- [Installation](docs/installation.md)
- [Configuration reference](docs/configuration.md)
- [Login/logout flow](docs/login-flow.md)
- [Webhooks](docs/webhooks.md)
- [Directory sync](docs/directory-sync.md)
- [Security model](docs/security.md)
- [Octane notes](docs/octane.md)
- [Upgrading](docs/upgrading.md)

## Security

If you discover a security vulnerability, please review [docs/security.md](docs/security.md) and
report it privately using this repository's *Report a vulnerability* feature (the **Security**
tab → **Report a vulnerability**, i.e. [GitHub private security advisories](https://docs.github.com/en/code-security/security-advisories/guidance-on-reporting-and-writing-information-about-vulnerabilities/privately-reporting-a-security-vulnerability)) — do not open a public issue.

## License

The MIT License (MIT). See [LICENSE](LICENSE) for more information.
