<?php

use App\Models\User;
use Raccount\Sso\Resolvers\DefaultUserResolver;

return [

    /*
    |----------------------------------------------------------------------
    | RAccount server
    |----------------------------------------------------------------------
    */

    'server' => [
        'base_url' => env('RACCOUNT_SSO_SERVER_URL'),
        'authorize_path' => '/oauth/authorize',
        'token_path' => '/oauth/token',
        'introspect_path' => '/oauth/introspect',
        'revoke_path' => '/oauth/revoke',
        'userinfo_path' => '/api/v1/userinfo',
        'ping_path' => '/api/v1/ping',
        'directory_path' => '/api/v1/directory/users',

        // Allow plain http outside local environments. Never enable in production.
        'allow_insecure' => env('RACCOUNT_SSO_ALLOW_INSECURE', false),
    ],

    /*
    |----------------------------------------------------------------------
    | OAuth client credentials (issued by the RAccount admin)
    |----------------------------------------------------------------------
    */

    'client' => [
        'id' => env('RACCOUNT_SSO_CLIENT_ID'),
        'secret' => env('RACCOUNT_SSO_CLIENT_SECRET'),
        'redirect_uri' => env('RACCOUNT_SSO_REDIRECT_URI'),
    ],

    // OAuth scopes requested during the authorization code flow.
    'scopes' => ['profile', 'email'],

    // Optional `prompt` parameter forwarded to /oauth/authorize (e.g. "consent").
    'prompt' => env('RACCOUNT_SSO_PROMPT'),

    // "optional": SSO routes coexist with local auth.
    // "exclusive": middleware alias `raccount.exclusive` can take over local auth routes.
    'mode' => 'optional',

    /*
    |----------------------------------------------------------------------
    | Routes
    |----------------------------------------------------------------------
    */

    'routes' => [
        'enabled' => true,
        'prefix' => 'raccount',
        'middleware' => ['web'],
        'logout_enabled' => true,
    ],

    /*
    |----------------------------------------------------------------------
    | Webhooks
    |----------------------------------------------------------------------
    */

    'webhooks' => [
        'enabled' => false,
        'path' => 'raccount/webhook',
        'middleware' => ['throttle:60,1'],

        // Supports an array so the secret can be rotated without downtime.
        'secrets' => array_filter([
            env('RACCOUNT_SSO_WEBHOOK_SECRET'),
            env('RACCOUNT_SSO_WEBHOOK_SECRET_PREVIOUS'),
        ]),

        // Accepted clock skew for X-RAccount-Timestamp, in seconds.
        'tolerance' => 300,

        // Register the SDK's built-in status listener for webhook events.
        'listeners_enabled' => true,
    ],

    /*
    |----------------------------------------------------------------------
    | Directory sync (machine-to-machine)
    |----------------------------------------------------------------------
    */

    'directory' => [
        'enabled' => env('RACCOUNT_SSO_DIRECTORY_ENABLED', false),
        'scope' => 'sync:read',
        'cache_store' => null,
        'page_limit' => 200,
    ],

    /*
    |----------------------------------------------------------------------
    | Local user resolution
    |----------------------------------------------------------------------
    */

    'user' => [
        'model' => env('RACCOUNT_SSO_USER_MODEL', User::class),
        'resolver' => DefaultUserResolver::class,

        // Reject logins whose RAccount email is not verified.
        'require_verified_email' => true,

        // Automatically link an existing local account when the verified
        // RAccount email matches. Disable to require manual linking.
        'auto_link_verified_email' => true,

        'email_column' => 'email',

        // Local column => UserInfo property applied when provisioning users.
        'attributes' => ['name' => 'name', 'email' => 'email'],
    ],

    /*
    |----------------------------------------------------------------------
    | Redirects (after login/logout and on OAuth errors)
    | on_error is a route name.
    |----------------------------------------------------------------------
    */

    'redirects' => [
        'after_login' => '/home',
        'after_logout' => '/',
        'on_error' => 'login',
    ],

    /*
    |----------------------------------------------------------------------
    | Exclusive mode: local auth route names taken over by the middleware.
    |----------------------------------------------------------------------
    */

    'exclusive' => [
        'routes' => ['login', 'register', 'password.request', 'password.reset', 'password.update'],
    ],

    /*
    |----------------------------------------------------------------------
    | Middleware behaviour
    | enforce_status toggles the `raccount.active` middleware check.
    |----------------------------------------------------------------------
    */

    'middleware' => [
        'enforce_status' => false,
    ],

    'button_label' => 'Login with RAccount',

    /*
    |----------------------------------------------------------------------
    | HTTP transport
    |----------------------------------------------------------------------
    */

    'http' => [
        'timeout' => 10,
        'connect_timeout' => 10,
        'attempts' => 3,
        'backoff_ms' => 200,
    ],
];
