# Installation

This guide walks you through installing `raccount/laravel-sso` into a Laravel 13 application and
registering your application with the RAccount identity provider.

## Requirements

- PHP 8.3 or newer
- Laravel 13
- A **confidential** RAccount OAuth client (a client id and client secret, with at least one
  registered redirect URI)

## 1. Install the package

```bash
composer require raccount/laravel-sso
```

The service provider (`Raccount\Sso\RaccountSsoServiceProvider`) and the `Raccount` facade are
auto-discovered by Laravel — no manual registration is needed.

## 2. Publish the assets

```bash
php artisan vendor:publish --tag=raccount-sso-config
php artisan vendor:publish --tag=raccount-sso-migrations
php artisan vendor:publish --tag=raccount-sso-views
```

| Tag | Destination | Purpose |
| --- | --- | --- |
| `raccount-sso-config` | `config/raccount-sso.php` | Full configuration reference (optional — sensible defaults ship with the package) |
| `raccount-sso-migrations` | `database/migrations/` | Creates the `raccount_accounts` and `raccount_webhook_events` tables |
| `raccount-sso-views` | `resources/views/vendor/raccount` | The `<x-raccount::button />` Blade component, for customization |

Publishing the config is optional: the package merges its defaults, so you only need to publish it
when you want to change non-env settings. The migrations are required. The views only need
publishing if you want to override the built-in markup.

## 3. Run the migrations

```bash
php artisan migrate
```

Two tables are created:

- `raccount_accounts` — one row per linked RAccount identity: the morph link to your local user,
  the profile snapshot, the encrypted access/refresh tokens, and the account status
  (`active` / `suspended` / `deleted`).
- `raccount_webhook_events` — the webhook claim table used for replay protection (event-id
  deduplication) and at-least-once processing.

## 4. Register your application with the RAccount admin

Send the following to the administrator of your RAccount instance:

- **Application name** — shown to users on the consent screen.
- **Logo** — at least 64×64 pixels, PNG or SVG with a transparent background.
- **Redirect URI(s)** — the exact callback URL(s). The default package route is
  `https://your-app.example.com/raccount/callback`. URIs must be HTTPS (see
  [Local development](#local-development) for the one exception) and are matched exactly — no
  wildcards, no trailing-slash variants, no query strings.
- **Scopes** — `profile email` for the standard login flow.
- **Client type** — **confidential**. The package authenticates to the token endpoint with the
  client secret (HTTP Basic), so public clients are not supported.

The admin will issue a **client id** and **client secret**. The secret is shown once at creation —
store it immediately.

If you plan to use [directory sync](directory-sync.md), also request a client-credentials client
with the `sync:read` scope.

## 5. Configure your environment

```env
RACCOUNT_SSO_SERVER_URL=https://account.reducates.id
RACCOUNT_SSO_CLIENT_ID=your-client-id
RACCOUNT_SSO_CLIENT_SECRET=your-client-secret
RACCOUNT_SSO_REDIRECT_URI=https://your-app.example.com/raccount/callback
```

Optional variables:

```env
RACCOUNT_SSO_PROMPT=consent
RACCOUNT_SSO_WEBHOOK_SECRET=
RACCOUNT_SSO_WEBHOOK_SECRET_PREVIOUS=
RACCOUNT_SSO_DIRECTORY_ENABLED=false
RACCOUNT_SSO_USER_MODEL=App\Models\User
RACCOUNT_SSO_ALLOW_INSECURE=false
```

See the [configuration reference](configuration.md) for every key and its default.

## 6. Add the login button

In your login Blade template:

```blade
<x-raccount::button class="btn btn-primary" />
```

The component renders an `<a>` pointing at the package's `raccount.login` route. The label
defaults to `Login with RAccount` (config `button_label`) and can be overridden per instance via
the `label` prop.

## 7. Verify the installation

```bash
php artisan raccount:check
```

The command checks your configuration (server URL is HTTPS, client credentials and redirect URI
are set), that the callback route is registered, that both package tables exist, that webhook
secrets are configured when webhooks are enabled, and finally that the RAccount server answers its
ping endpoint. Every failed check prints a hint about how to fix it. Exit code is non-zero when any
check fails, so you can wire it into a deploy pipeline or a scheduled health probe.

## Local development

RAccount requires HTTPS redirect URIs in every non-local environment. For purely local development
against a loopback RAccount instance you may relax this in two places at once:

```env
RACCOUNT_SSO_SERVER_URL=http://localhost:8080
RACCOUNT_SSO_ALLOW_INSECURE=true
```

`RACCOUNT_SSO_ALLOW_INSECURE` is **only honored when `APP_ENV=local`** — in any other environment
the package throws a `ConfigurationInvalid` exception if the server URL is not `https://`. Loopback
redirect URIs such as `http://127.0.0.1:8000/raccount/callback` are accepted by the RAccount
server for development clients only; never register them on a production client.

## Next steps

- [Configuration reference](configuration.md)
- [Login/logout flow](login-flow.md)
- [Webhooks](webhooks.md)
- [Directory sync](directory-sync.md)
- [Security model](security.md)
- [Octane notes](octane.md)
