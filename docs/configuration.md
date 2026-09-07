# Configuration reference

All configuration lives under the `raccount-sso` key in `config/raccount-sso.php`. The file merges
with the package defaults, so you only need to publish and edit it when overriding non-env
settings.

## Environment variables

| Variable | Config key |
| --- | --- |
| `RACCOUNT_SSO_SERVER_URL` | `server.base_url` |
| `RACCOUNT_SSO_CLIENT_ID` | `client.id` |
| `RACCOUNT_SSO_CLIENT_SECRET` | `client.secret` |
| `RACCOUNT_SSO_REDIRECT_URI` | `client.redirect_uri` |
| `RACCOUNT_SSO_PROMPT` | `prompt` |
| `RACCOUNT_SSO_ALLOW_INSECURE` | `server.allow_insecure` |
| `RACCOUNT_SSO_WEBHOOK_SECRET` | `webhooks.secrets[0]` |
| `RACCOUNT_SSO_WEBHOOK_SECRET_PREVIOUS` | `webhooks.secrets[1]` |
| `RACCOUNT_SSO_DIRECTORY_ENABLED` | `directory.enabled` |
| `RACCOUNT_SSO_USER_MODEL` | `user.model` |

## Full key reference

### `server`

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `server.base_url` | `string` | `RACCOUNT_SSO_SERVER_URL` | Base URL of the RAccount server. Must be `https://` outside local development. |
| `server.authorize_path` | `string` | `/oauth/authorize` | Authorization endpoint path. |
| `server.token_path` | `string` | `/oauth/token` | Token endpoint path (code exchange, refresh, client credentials). |
| `server.introspect_path` | `string` | `/oauth/introspect` | Token introspection endpoint path. |
| `server.revoke_path` | `string` | `/oauth/revoke` | Token revocation endpoint path. |
| `server.userinfo_path` | `string` | `/api/v1/userinfo` | OpenID-style userinfo endpoint path. |
| `server.ping_path` | `string` | `/api/v1/ping` | Health endpoint used by `raccount:check`. |
| `server.directory_path` | `string` | `/api/v1/directory/users` | M2M directory endpoint path. |
| `server.allow_insecure` | `bool` | `RACCOUNT_SSO_ALLOW_INSECURE` (`false`) | Permit plain `http://` server URLs. Only honored when `APP_ENV=local`. Never enable in production. |

### `client`

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `client.id` | `string` | `RACCOUNT_SSO_CLIENT_ID` | OAuth client id issued by the RAccount admin. |
| `client.secret` | `string` | `RACCOUNT_SSO_CLIENT_SECRET` | OAuth client secret (confidential clients only). |
| `client.redirect_uri` | `string` | `RACCOUNT_SSO_REDIRECT_URI` | The callback URI registered server-side; must match exactly. |

### `scopes`

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `scopes` | `string[]` | `['profile', 'email']` | OAuth scopes requested during the authorization-code flow. |

### `prompt`

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `prompt` | `?string` | `RACCOUNT_SSO_PROMPT` (`null`) | Optional `prompt` parameter forwarded to `/oauth/authorize` (e.g. `consent` to force the consent screen, `login` to force re-authentication). |

### `mode`

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `mode` | `'optional'\|'exclusive'` | `'optional'` | Documents how SSO relates to local auth. `optional`: SSO routes coexist with local auth. `exclusive`: you intend SSO to be the only way in — pair this with the `raccount.exclusive` middleware taking over the route names in `exclusive.routes`. The key is declarative; enforcement comes from the middleware you register. |

### `routes`

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `routes.enabled` | `bool` | `true` | Register the login/callback/logout routes. Disable if you want to mount them yourself. |
| `routes.prefix` | `string` | `raccount` | URI prefix for the flow routes. |
| `routes.middleware` | `string[]` | `['web']` | Middleware stack applied to the flow routes. Keep the session (`web`) group — state and PKCE use the session. |
| `routes.logout_enabled` | `bool` | `true` | Register `GET /raccount/logout` (`raccount.logout`). Disable if you prefer POST-only logout and want to wire revocation into your own logout action. |

The three routes are: `GET /raccount/redirect` (name `raccount.login`), `GET /raccount/callback`
(name `raccount.callback`), and `GET /raccount/logout` (name `raccount.logout`, gated by
`routes.logout_enabled`).

### `webhooks`

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `webhooks.enabled` | `bool` | `false` | Register `POST raccount/webhook` and (see `listeners_enabled`) the built-in status listener. |
| `webhooks.path` | `string` | `raccount/webhook` | URI path of the webhook endpoint. |
| `webhooks.middleware` | `string[]` | `['throttle:60,1']` | Middleware for the webhook route. It is intentionally **not** in the `web` group, so no CSRF protection applies — authenticity comes from the HMAC signature instead. |
| `webhooks.secrets` | `string[]` | `array_filter([RACCOUNT_SSO_WEBHOOK_SECRET, RACCOUNT_SSO_WEBHOOK_SECRET_PREVIOUS])` | HMAC secrets accepted for signature verification. The array exists so you can rotate without downtime. |
| `webhooks.tolerance` | `int` | `300` | Accepted clock skew for `X-RAccount-Timestamp`, in seconds. |
| `webhooks.listeners_enabled` | `bool` | `true` | Auto-register the built-in `UpdateAccountStatus` listener on the user webhook events. |

### `directory`

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `directory.enabled` | `bool` | `RACCOUNT_SSO_DIRECTORY_ENABLED` (`false`) | Feature flag for M2M directory sync. Does not register routes; governs whether the sync service is expected to be used. |
| `directory.scope` | `string` | `sync:read` | Scope requested with the client-credentials grant. |
| `directory.cache_store` | `?string` | `null` | Cache store for the M2M token. `null` = the application default. Use a shared store (Redis, database) when running multiple workers. |
| `directory.page_limit` | `int` | `200` | Page size requested per directory request. |

### `user`

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `user.model` | `class-string` | `RACCOUNT_SSO_USER_MODEL` (`App\Models\User`) | The local user model provisioned/linked by the default resolver. |
| `user.resolver` | `class-string` | `DefaultUserResolver::class` | Implementation of `Raccount\Sso\Contracts\UserResolver` bound into the container. |
| `user.require_verified_email` | `bool` | `true` | Reject logins whose RAccount email is not verified. |
| `user.auto_link_verified_email` | `bool` | `true` | Automatically link an existing local account when the verified RAccount email matches (case-insensitively). Disable to require manual linking. |
| `user.email_column` | `string` | `email` | Column on `user.model` searched for the email match. |
| `user.attributes` | `array<string, string>` | `['name' => 'name', 'email' => 'email']` | Map of local column => `UserInfo` property applied when JIT-provisioning a user. |

### `redirects`

> **Note the mixed semantics:** `after_login` and `after_logout` are **paths** (strings passed to
> `redirect()->to()` / `redirect()->intended()`), while `on_error` is a **route name** (passed to
> `redirect()->route()`). Pointing `on_error` at a path will throw a `RouteNotFoundException`.

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `redirects.after_login` | `string` | `/home` | Fallback path after a successful login when the user had no intended URL. |
| `redirects.after_logout` | `string` | `/` | Path after logout. |
| `redirects.on_error` | `string` (route name) | `login` | Route the user is redirected to when the OAuth flow fails (`error=access_denied`), the resolver denies linkage, the account is suspended/deleted, or the `raccount.active` middleware logs the user out. A `raccount-sso.error` flash message is set for display. |

### `exclusive`

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `exclusive.routes` | `string[]` | `['login', 'register', 'password.request', 'password.reset', 'password.update']` | Route names that the `raccount.exclusive` middleware redirects to `raccount.login` when the visitor is a guest. Empty array disables the takeover. |

### `middleware`

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `middleware.enforce_status` | `bool` | `false` | Toggles the `raccount.active` middleware check. When `false` the middleware is a pass-through; when `true` requests from users whose linked RAccount account is not `active` are logged out and redirected to `redirects.on_error`. There is no env var for this key — set it in `config/raccount-sso.php`. |

### `button_label`

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `button_label` | `string` | `Login with RAccount` | Default label rendered by `<x-raccount::button />` when no `label` prop is given. |

### `http`

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `http.timeout` | `int` | `10` | Total request timeout in seconds for calls to the RAccount server. |
| `http.connect_timeout` | `int` | `10` | Connection timeout in seconds. |
| `http.attempts` | `int` | `3` | Maximum attempts for connection errors and 5xx responses (jittered backoff between attempts). 4xx responses are never retried. |
| `http.backoff_ms` | `int` | `200` | Base backoff in milliseconds; scaled by the attempt number plus jitter. |

## Database index recommendations

The default resolver searches for an existing local user with
`WHERE LOWER(email_column) = ?` (lowercased with `mb_strtolower`), because RAccount email linking
is case-insensitive. Two index recommendations follow from that:

1. **Functional index on the lowered email.** On large user tables a plain index on `email` cannot
   serve the `LOWER(col) = ?` predicate — Postgres will seq-scan. Add a functional index:

   ```sql
   -- PostgreSQL
   CREATE INDEX users_email_lower_idx ON users (LOWER(email));

   -- MySQL 8+ (the expression must match the collation semantics)
   CREATE INDEX users_email_lower_idx ON users ((LOWER(email)));
   ```

   On MySQL with a case-insensitive (`_ci`) collation a plain index on `email` already serves the
   predicate, since `LOWER(col) = ?` and `col = ?` are equivalent there — verify with `EXPLAIN`.

2. **Unique index on the email column.** When two concurrent first logins arrive for the same
   verified email, both requests can pass the "does this user exist?" check before either inserts,
   and the resolver would provision two local users for one mailbox. The `raccount_accounts` table
   enforces uniqueness of `raccount_sub`, but only a **unique index on the email column in your
   host app's users table** makes duplicate provisioning impossible. If your application genuinely
   allows duplicate emails, write a custom resolver that serializes provisioning (or set
   `user.auto_link_verified_email => false` and handle linking manually).

## Custom resolver

Replace the default resolution strategy by implementing the contract:

```php
<?php

namespace App\Sso;

use Illuminate\Contracts\Auth\Authenticatable;
use Raccount\Sso\Client\Dto\UserInfo;
use Raccount\Sso\Contracts\UserResolver;

final class TenantUserResolver implements UserResolver
{
    public function resolve(UserInfo $userinfo): Authenticatable
    {
        // Your logic: find, link, or provision the local user.
        // Throw Raccount\Sso\Exceptions\AccountLinkageDenied to reject the
        // login with a user-facing message.
    }
}
```

Bind it in `config/raccount-sso.php`:

```php
'user' => [
    'resolver' => \App\Sso\TenantUserResolver::class,
],
```

The contract is resolved through the container on every callback, so the class can take constructor
dependencies. Anything thrown that is not an `AccountLinkageDenied` (which includes its subclass
`EmailNotVerified`) propagates as a 500 — reserve exceptions for genuinely exceptional cases.

## Custom attribute map

When JIT-provisioning, `user.attributes` maps local columns to `Raccount\Sso\Client\Dto\UserInfo`
public properties — `sub`, `name`, `picture`, `email`, `emailVerified`, `updatedAt`. Attributes
whose property resolves to `null` are simply not set:

```php
'user' => [
    'attributes' => [
        'name' => 'name',
        'email' => 'email',
        'avatar' => 'picture',
    ],
],
```

This map only applies to newly provisioned users. Later profile changes flow in through webhooks
(the built-in listener updates the `raccount_accounts` snapshot) or your own logic — the login
callback itself does not rewrite existing users' columns.
