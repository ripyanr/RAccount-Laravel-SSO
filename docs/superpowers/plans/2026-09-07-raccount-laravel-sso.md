# raccount/laravel-sso Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build `raccount/laravel-sso`, a Laravel 13 package that integrates client applications with the RAccount SSO server (OAuth 2.0 authorization-code + PKCE, refresh rotation, webhooks, M2M directory sync).

**Architecture:** A single Laravel package (Spatie skeleton layout) with a custom OAuth2 client on top of the Laravel HTTP client. Modules: Client (transport + DTOs + errors), Flow (redirect/callback/logout controllers), Resolver (local user linkage), TokenService (encrypted persistence + rotation), Webhooks (HMAC verification + events), Directory (M2M cursor pagination), Commands (diagnostics/sync/prune). No Socialite, no framework-agnostic core, zero runtime dependencies beyond illuminate/*.

**Tech Stack:** PHP 8.3+, Laravel 13 (illuminate/http, contracts, support, database), Pest 4 + orchestra/testbench ^11.0, Pint, Larastan 3.

**Spec:** `docs/superpowers/specs/2026-09-07-raccount-laravel-sso-design.md` (read it first).
**Server contract:** `/home/ripyanr/Developments/EN-COLLABORATE/raccount/docs/integration-guide.md` + `docs/openapi/openapi.yaml`.

## Global Constraints

- Working directory: `/home/ripyanr/Developments/EN-COLLABORATE/raccount-sdk` (git repo on branch `main` already exists with 2 docs commits).
- Package name `raccount/laravel-sso`; PSR-4 `Raccount\Sso\` → `src/`; tests `Raccount\Sso\Tests\` → `tests/`.
- PHP `^8.3`, Laravel `^13.0` only. Runtime deps allowed: `illuminate/contracts`, `illuminate/http`, `illuminate/support`, `illuminate/database`. Nothing else.
- Config key `raccount-sso`; env prefix `RACCOUNT_SSO_*`. **No closures in config** (must survive `config:cache`); `env()` only inside config files.
- Route names: `raccount.login`, `raccount.callback`, `raccount.logout`. Middleware aliases: `raccount.active`, `raccount.exclusive`.
- Server endpoints (paths are config-overridable, these are defaults): `GET /oauth/authorize`, `POST /oauth/token`, `POST /oauth/introspect`, `POST /oauth/revoke`, `GET /api/v1/userinfo`, `GET /api/v1/ping`, `GET /api/v1/directory/users`.
- `/oauth/*` errors are RFC 6749 JSON (`{"error","error_description"}`); `/api/v1/*` errors are RFC 9457 Problem Details. `429` carries `Retry-After` (seconds).
- Webhook headers: `X-RAccount-Signature: sha256=<hex HMAC-SHA256 of raw body>`, `X-RAccount-Event-Id` (ULID), `X-RAccount-Event-Type`, `X-RAccount-Timestamp` (ISO-8601 UTC, ±5 min window). Payload shape: `{id, type, occurred_at, actor{type,id}, data{userinfo-shaped}, changed?}`.
- `invalid_grant` from the token endpoint means "re-authenticate" — NEVER retry it. Refresh tokens rotate on every use.
- Tokens at rest MUST use Laravel encrypted casts; secrets only via env; token values must never appear in exception messages or logs.
- Code, comments, docs, and commit messages in English. Conventional commits (`feat:`, `fix:`, `docs:`, `test:`, `chore:`).
- Style: Laravel Pint preset (default). Static analysis: Larastan level 6 — zero errors allowed.
- TDD: every task writes the failing test first, sees it fail, then implements.
- Before each commit run `vendor/bin/pint --dirty` and `vendor/bin/phpstan analyse --no-progress` (after Task 1 exists) and `vendor/bin/pest`.

---

### Task 1: Package skeleton, tooling, and test harness

**Files:**
- Create: `composer.json`, `.gitignore`, `.editorconfig`, `LICENSE`, `phpunit.xml`, `phpstan.neon.dist`, `.github/workflows/run-tests.yml`, `.github/workflows/fix-php-code-style.yml`, `.github/ISSUE_TEMPLATE/01_bug_report.md`, `.github/ISSUE_TEMPLATE/02_feature_request.md`, `.github/PULL_REQUEST_TEMPLATE.md`, `CONTRIBUTING.md`, `CHANGELOG.md`
- Create: `tests/Pest.php`, `tests/TestCase.php`, `tests/Fixtures/User.php`, `tests/Fixtures/migrations/0000_01_01_000000_create_users_table.php`
- Test: `tests/Unit/SanityTest.php`

**Interfaces:**
- Consumes: nothing (first task).
- Produces: `Raccount\Sso\Tests\TestCase` (all later test classes extend it); test fixture model `Raccount\Sso\Tests\Fixtures\User` (table `users`: id, name, email unique, password nullable, remember_token, timestamps); composer scripts `test` (pest), `analyse` (phpstan), `format` (pint).

- [ ] **Step 1: Create `composer.json`**

```json
{
    "$schema": "https://getcomposer.org/schema.json",
    "name": "raccount/laravel-sso",
    "description": "Single Sign-On client SDK for RAccount - OAuth 2.0 authorization code + PKCE, refresh rotation, webhooks, and directory sync for Laravel applications.",
    "keywords": ["raccount", "laravel", "sso", "oauth2", "single-sign-on"],
    "homepage": "https://github.com/raccount/laravel-sso",
    "license": "MIT",
    "authors": [
        {
            "name": "RAccount Team"
        }
    ],
    "require": {
        "php": "^8.3",
        "illuminate/contracts": "^13.0",
        "illuminate/database": "^13.0",
        "illuminate/http": "^13.0",
        "illuminate/support": "^13.0"
    },
    "require-dev": {
        "larastan/larastan": "^3.11",
        "laravel/pint": "^1.27",
        "orchestra/testbench": "^11.0",
        "pestphp/pest": "^4.7",
        "pestphp/pest-plugin-laravel": "^4.1"
    },
    "autoload": {
        "psr-4": {
            "Raccount\\Sso\\": "src"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Raccount\\Sso\\Tests\\": "tests"
        }
    },
    "scripts": {
        "test": "vendor/bin/pest",
        "test-coverage": "vendor/bin/pest --coverage",
        "analyse": "vendor/bin/phpstan analyse",
        "format": "vendor/bin/pint"
    },
    "config": {
        "allow-plugins": {
            "pestphp/pest-plugin": true
        },
        "sort-packages": true
    },
    "minimum-stability": "stable",
    "prefer-stable": true,
    "extra": {
        "laravel": {
            "providers": [
                "Raccount\\Sso\\RaccountSsoServiceProvider"
            ],
            "aliases": {
                "Raccount": "Raccount\\Sso\\Facades\\Raccount"
            }
        }
    }
}
```

- [ ] **Step 2: Create supporting files**

`.gitignore`:

```
/build
/docs
/vendor
/.phpunit.cache
/.phpunit.result.cache
/.php_cs.cache
/.php-cs-fixer.cache
/node_modules
/.idea
/.vscode
.env
.env.backup
auth.json
npm-debug.log
yarn-error.log
```

`.editorconfig`:

```
root = true

[*]
charset = utf-8
end_of_line = lf
indent_size = 4
indent_style = space
insert_final_newline = true
trim_trailing_whitespace = true

[*.md]
trim_trailing_whitespace = false

[*.{yml,yaml,neon,json}]
indent_size = 2
```

`LICENSE` (MIT, full text, copyright holder `RAccount Team`, year `2026`):

```
MIT License

Copyright (c) 2026 RAccount Team

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

`phpunit.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true">
    <testsuites>
        <testsuite name="Testbench">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
    <php>
        <env name="APP_KEY" value="base64:2fl+Ktvkfl+Fuz4QpX7529bJcLh5VbJBroQ95cJ0eH4="/>
    </php>
</phpunit>
```

`phpstan.neon.dist`:

```
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    level: 6
    paths:
        - src
        - tests
```

- [ ] **Step 3: Create CI workflows and templates**

`.github/workflows/run-tests.yml`:

```yaml
name: run-tests

on:
  push:
  pull_request:

jobs:
  test:
    runs-on: ubuntu-latest
    strategy:
      fail-fast: true
      matrix:
        php: [8.3, 8.4]
        stability: [prefer-lowest, prefer-stable]

    name: PHP ${{ matrix.php }} - ${{ matrix.stability }}

    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          extensions: dom, curl, libxml, mbstring, zip, pdo, sqlite, pdo_sqlite
          coverage: none

      - name: Install dependencies
        run: composer update --${{ matrix.stability }} --prefer-dist --no-interaction --no-progress

      - name: Execute tests
        run: vendor/bin/pest

  analyse:
    runs-on: ubuntu-latest
    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: 8.4
          extensions: dom, curl, libxml, mbstring, zip, pdo, sqlite, pdo_sqlite
          coverage: none

      - name: Install dependencies
        run: composer update --prefer-dist --no-interaction --no-progress

      - name: Execute static analysis
        run: vendor/bin/phpstan analyse --no-progress

  style:
    runs-on: ubuntu-latest
    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: 8.4
          coverage: none

      - name: Install dependencies
        run: composer update --prefer-dist --no-interaction --no-progress

      - name: Execute code style check
        run: vendor/bin/pint --test
```

`.github/workflows/fix-php-code-style.yml`:

```yaml
name: fix-php-code-style

on:
  push:
    paths:
      - '**.php'

permissions:
  contents: write

jobs:
  style:
    runs-on: ubuntu-latest
    steps:
      - name: Checkout code
        uses: actions/checkout@v4
        with:
          ref: ${{ github.head_ref }}

      - name: Fix PHP code style issues
        uses: aglipanci/laravel-pint-action@latest

      - name: Commit changes
        uses: stefanzweifel/git-auto-commit-action@v5
        with:
          commit_message: "chore: fix code style"
```

`.github/ISSUE_TEMPLATE/01_bug_report.md`:

```markdown
---
name: Bug report
about: Create a report to help us improve
title: ''
labels: bug
assignees: ''
---

**Describe the bug**
A clear and concise description of what the bug is.

**To reproduce**
Steps to reproduce the behavior.

**Environment**
- Package version:
- Laravel version:
- PHP version:

**Additional context**
Logs, config (redact secrets), and the RAccount `X-Request-Id` if a server response was involved.
```

`.github/ISSUE_TEMPLATE/02_feature_request.md`:

```markdown
---
name: Feature request
about: Suggest an idea for this project
title: ''
labels: enhancement
assignees: ''
---

**Is your feature request related to a problem?**
A clear and concise description of the problem.

**Describe the solution you'd like**
What you want to happen.

**Describe alternatives you've considered**
Any alternative solutions or features you've considered.
```

`.github/PULL_REQUEST_TEMPLATE.md`:

```markdown
## Description

Brief description of the change.

## Motivation / context

Why this change is needed.

## Checklist

- [ ] Tests added/updated
- [ ] Documentation updated (README / docs/)
- [ ] `vendor/bin/pest`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse` pass
```

`CONTRIBUTING.md`:

```markdown
# Contributing

Thanks for considering to contribute to `raccount/laravel-sso`!

## Setting up locally

```bash
git clone <your-fork>
cd laravel-sso
composer install
composer test
```

## Guidelines

- Follow the existing code style (`composer format`, Laravel Pint preset).
- Add or update Pest tests for every change (`composer test`).
- Keep static analysis clean: `composer analyse` (Larastan level 6).
- Write commit messages using the Conventional Commits format.
- Security-relevant issues: do NOT open a public issue; contact the maintainers directly.
```

`CHANGELOG.md`:

```markdown
# Changelog

All notable changes to `raccount/laravel-sso` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Initial development release.
```

- [ ] **Step 4: Create the test harness**

`tests/TestCase.php`:

```php
<?php

namespace Raccount\Sso\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Raccount\Sso\RaccountSsoServiceProvider;
use Raccount\Sso\Tests\Fixtures\User;

abstract class TestCase extends OrchestraTestCase
{
    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [RaccountSsoServiceProvider::class];
    }

    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:2fl+Ktvkfl+Fuz4QpX7529bJcLh5VbJBroQ95cJ0eH4=');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('auth.providers.users.model', User::class);

        $app['config']->set('raccount-sso.server.base_url', 'https://account.test');
        $app['config']->set('raccount-sso.client.id', '11111111-1111-1111-1111-111111111111');
        $app['config']->set('raccount-sso.client.secret', str_repeat('a', 64));
        $app['config']->set('raccount-sso.client.redirect_uri', 'https://app.test/raccount/callback');
        $app['config']->set('raccount-sso.user.model', User::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
```

Note: `RaccountSsoServiceProvider` does not exist yet — create a minimal placeholder in this task so the harness boots (it will be extended by later tasks).

`src/RaccountSsoServiceProvider.php` (minimal placeholder for now):

```php
<?php

namespace Raccount\Sso;

use Illuminate\Support\ServiceProvider;

final class RaccountSsoServiceProvider extends ServiceProvider
{
}
```

`tests/Pest.php`:

```php
<?php

uses(Raccount\Sso\Tests\TestCase::class)->in('Feature', 'Unit');
```

`tests/Fixtures/User.php`:

```php
<?php

namespace Raccount\Sso\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];
}
```

`tests/Fixtures/migrations/0000_01_01_000000_create_users_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps(3);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
```

- [ ] **Step 5: Write sanity test and verify the harness**

`tests/Unit/SanityTest.php`:

```php
<?php

it('boots the test harness with an app key and sqlite', function (): void {
    expect(config('app.key'))->toStartWith('base64:')
        ->and(config('database.default'))->toBe('testing')
        ->and(\Illuminate\Support\Facades\Schema::hasTable('users'))->toBeTrue();
});
```

Run:

```bash
composer update --prefer-dist --no-interaction --no-progress
vendor/bin/pest
```

Expected: 1 passing test. Then `vendor/bin/pint` and `vendor/bin/phpstan analyse` (should pass on the minimal codebase).

- [ ] **Step 6: Commit**

```bash
git add composer.json composer.lock .gitignore .editorconfig LICENSE phpunit.xml phpstan.neon.dist .github CONTRIBUTING.md CHANGELOG.md src tests
git commit -m "chore: package skeleton, tooling, and test harness"
```

---

### Task 2: Config file, DTOs, and exception hierarchy

**Files:**
- Create: `src/config/raccount-sso.php`
- Create: `src/Client/Dto/TokenPair.php`, `src/Client/Dto/UserInfo.php`, `src/Client/Dto/DirectoryUser.php`, `src/Client/Dto/DirectoryPage.php`, `src/Client/Dto/IntrospectionResult.php`
- Create: `src/Exceptions/RaccountException.php`, `src/Exceptions/ConfigurationInvalid.php`, `src/Exceptions/RequestFailed.php`, `src/Exceptions/RateLimited.php`, `src/Exceptions/TokenExchangeFailed.php`, `src/Exceptions/InvalidGrant.php`, `src/Exceptions/UserInfoFailed.php`, `src/Exceptions/AccountLinkageDenied.php`, `src/Exceptions/EmailNotVerified.php`
- Test: `tests/Unit/DtoTest.php`

**Interfaces:**
- Consumes: `TestCase` (Task 1).
- Produces (used by Tasks 4–12):
  - `TokenPair`: readonly props `string $accessToken`, `?string $refreshToken`, `int $expiresIn`, `array $scopes`, `CarbonImmutable $issuedAt`; methods `expiresAt(): CarbonImmutable`, `static fromTokenResponse(array $body, ?CarbonImmutable $now = null): self`.
  - `UserInfo`: readonly props `string $sub`, `?string $name`, `?string $picture`, `?CarbonImmutable $updatedAt`, `?string $email`, `?bool $emailVerified`; `static fromClaimSet(array $claims): self` (absent keys → null).
  - `DirectoryUser`: readonly props `string $sub`, `string $name`, `string $email`, `bool $emailVerified`, `string $status`, `CarbonImmutable $updatedAt`; `static fromRecord(array $record): self`.
  - `DirectoryPage`: readonly props `array $data` (list of `DirectoryUser`), `?string $nextCursor`; `static fromResponse(array $body): self`.
  - `IntrospectionResult`: readonly props `bool $active`, `?string $tokenType`, `?string $clientId`, `array $scopes`, `?CarbonImmutable $expiresAt`, `?string $sub`; `static fromVerdict(array $body): self`.
  - Exceptions: `abstract RaccountException extends RuntimeException`; `ConfigurationInvalid`, `RequestFailed`, `RateLimited extends RaccountException` (public readonly `?int $retryAfterSeconds`), `TokenExchangeFailed` (public readonly `string $errorCode`, `string $errorDescription`), `InvalidGrant extends TokenExchangeFailed`, `UserInfoFailed` (public readonly `int $status`), `AccountLinkageDenied`, `EmailNotVerified extends AccountLinkageDenied`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/DtoTest.php`:

```php
<?php

use Carbon\CarbonImmutable;
use Raccount\Sso\Client\Dto\DirectoryPage;
use Raccount\Sso\Client\Dto\DirectoryUser;
use Raccount\Sso\Client\Dto\IntrospectionResult;
use Raccount\Sso\Client\Dto\TokenPair;
use Raccount\Sso\Client\Dto\UserInfo;

it('builds a token pair from an oauth token response', function (): void {
    $now = CarbonImmutable::parse('2026-09-07T10:00:00Z');
    $pair = TokenPair::fromTokenResponse([
        'token_type' => 'Bearer',
        'expires_in' => 900,
        'access_token' => 'at',
        'refresh_token' => 'rt',
        'scope' => 'profile email',
    ], $now);

    expect($pair->accessToken)->toBe('at')
        ->and($pair->refreshToken)->toBe('rt')
        ->and($pair->expiresIn)->toBe(900)
        ->and($pair->scopes)->toBe(['profile', 'email'])
        ->and($pair->expiresAt()->equalTo($now->addSeconds(900)))->toBeTrue();
});

it('tolerates token responses without refresh token or scope', function (): void {
    $pair = TokenPair::fromTokenResponse(['access_token' => 'at', 'expires_in' => 60]);

    expect($pair->refreshToken)->toBeNull()
        ->and($pair->scopes)->toBe([]);
});

it('builds userinfo with absent claims as null', function (): void {
    $info = UserInfo::fromClaimSet([
        'sub' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
        'name' => 'Budi Santoso',
        'updated_at' => '2026-09-07T00:00:00Z',
    ]);

    expect($info->sub)->toBe('0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0')
        ->and($info->name)->toBe('Budi Santoso')
        ->and($info->email)->toBeNull()
        ->and($info->emailVerified)->toBeNull()
        ->and($info->picture)->toBeNull()
        ->and($info->updatedAt?->toIso8601ZuluString())->toBe('2026-09-07T00:00:00Z');
});

it('builds a directory user and page from records', function (): void {
    $page = DirectoryPage::fromResponse([
        'data' => [
            [
                'sub' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
                'name' => 'Budi',
                'email' => 'budi@example.com',
                'email_verified' => true,
                'status' => 'active',
                'updated_at' => '2026-09-07T00:00:00Z',
            ],
        ],
        'next_cursor' => 'CUR1',
    ]);

    expect($page->data)->toHaveCount(1)
        ->and($page->data[0])->toBeInstanceOf(DirectoryUser::class)
        ->and($page->data[0]->emailVerified)->toBeTrue()
        ->and($page->data[0]->status)->toBe('active')
        ->and($page->nextCursor)->toBe('CUR1');
});

it('builds an introspection verdict', function (): void {
    $result = IntrospectionResult::fromVerdict([
        'active' => true,
        'token_type' => 'access_token',
        'client_id' => '11111111-1111-1111-1111-111111111111',
        'scope' => 'profile email',
        'exp' => 1798760400,
        'sub' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
    ]);

    expect($result->active)->toBeTrue()
        ->and($result->scopes)->toBe(['profile', 'email'])
        ->and($result->sub)->toBe('0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0')
        ->and($result->expiresAt?->getTimestamp())->toBe(1798760400);
});

it('builds an inactive introspection verdict with minimal fields', function (): void {
    $result = IntrospectionResult::fromVerdict(['active' => false]);

    expect($result->active)->toBeFalse()
        ->and($result->sub)->toBeNull()
        ->and($result->expiresAt)->toBeNull();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Unit/DtoTest.php`
Expected: FAIL — classes `Raccount\Sso\Client\Dto\*` not found.

- [ ] **Step 3: Write the config file**

`src/config/raccount-sso.php`:

```php
<?php

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
            env('RACCOUNT_WEBHOOK_SECRET'),
            env('RACCOUNT_WEBHOOK_SECRET_PREVIOUS'),
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
        'model' => env('RACCOUNT_SSO_USER_MODEL', App\Models\User::class),
        'resolver' => Raccount\Sso\Resolvers\DefaultUserResolver::class,

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
```

- [ ] **Step 4: Write the DTOs**

`src/Client/Dto/TokenPair.php`:

```php
<?php

namespace Raccount\Sso\Client\Dto;

use Carbon\CarbonImmutable;

final class TokenPair
{
    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public readonly string $accessToken,
        public readonly ?string $refreshToken,
        public readonly int $expiresIn,
        public readonly array $scopes,
        public readonly CarbonImmutable $issuedAt,
    ) {}

    /**
     * @param  array<string, mixed>  $body
     */
    public static function fromTokenResponse(array $body, ?CarbonImmutable $now = null): self
    {
        $now ??= CarbonImmutable::now();
        $scope = trim((string) ($body['scope'] ?? ''));

        return new self(
            accessToken: (string) ($body['access_token'] ?? ''),
            refreshToken: isset($body['refresh_token']) ? (string) $body['refresh_token'] : null,
            expiresIn: (int) ($body['expires_in'] ?? 900),
            scopes: $scope === '' ? [] : preg_split('/\s+/', $scope) ?: [],
            issuedAt: $now,
        );
    }

    public function expiresAt(): CarbonImmutable
    {
        return $this->issuedAt->addSeconds($this->expiresIn);
    }
}
```

`src/Client/Dto/UserInfo.php`:

```php
<?php

namespace Raccount\Sso\Client\Dto;

use Carbon\CarbonImmutable;

final class UserInfo
{
    public function __construct(
        public readonly string $sub,
        public readonly ?string $name,
        public readonly ?string $picture,
        public readonly ?CarbonImmutable $updatedAt,
        public readonly ?string $email,
        public readonly ?bool $emailVerified,
    ) {}

    /**
     * Claims outside the granted scopes are absent keys — never nulls.
     *
     * @param  array<string, mixed>  $claims
     */
    public static function fromClaimSet(array $claims): self
    {
        return new self(
            sub: (string) ($claims['sub'] ?? ''),
            name: isset($claims['name']) ? (string) $claims['name'] : null,
            picture: isset($claims['picture']) ? (string) $claims['picture'] : null,
            updatedAt: isset($claims['updated_at']) && $claims['updated_at'] !== null
                ? CarbonImmutable::parse((string) $claims['updated_at'])
                : null,
            email: isset($claims['email']) ? (string) $claims['email'] : null,
            emailVerified: isset($claims['email_verified']) ? (bool) $claims['email_verified'] : null,
        );
    }
}
```

`src/Client/Dto/DirectoryUser.php`:

```php
<?php

namespace Raccount\Sso\Client\Dto;

use Carbon\CarbonImmutable;

final class DirectoryUser
{
    public function __construct(
        public readonly string $sub,
        public readonly string $name,
        public readonly string $email,
        public readonly bool $emailVerified,
        public readonly string $status,
        public readonly CarbonImmutable $updatedAt,
    ) {}

    /**
     * @param  array<string, mixed>  $record
     */
    public static function fromRecord(array $record): self
    {
        return new self(
            sub: (string) ($record['sub'] ?? ''),
            name: (string) ($record['name'] ?? ''),
            email: (string) ($record['email'] ?? ''),
            emailVerified: (bool) ($record['email_verified'] ?? false),
            status: (string) ($record['status'] ?? 'active'),
            updatedAt: CarbonImmutable::parse((string) ($record['updated_at'] ?? 'now')),
        );
    }
}
```

`src/Client/Dto/DirectoryPage.php`:

```php
<?php

namespace Raccount\Sso\Client\Dto;

final class DirectoryPage
{
    /**
     * @param  list<DirectoryUser>  $data
     */
    public function __construct(
        public readonly array $data,
        public readonly ?string $nextCursor,
    ) {}

    /**
     * @param  array<string, mixed>  $body
     */
    public static function fromResponse(array $body): self
    {
        return new self(
            data: array_map(
                static fn (array $record): DirectoryUser => DirectoryUser::fromRecord($record),
                array_values((array) ($body['data'] ?? [])),
            ),
            nextCursor: isset($body['next_cursor']) && $body['next_cursor'] !== null
                ? (string) $body['next_cursor']
                : null,
        );
    }
}
```

`src/Client/Dto/IntrospectionResult.php`:

```php
<?php

namespace Raccount\Sso\Client\Dto;

use Carbon\CarbonImmutable;

final class IntrospectionResult
{
    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public readonly bool $active,
        public readonly ?string $tokenType,
        public readonly ?string $clientId,
        public readonly array $scopes,
        public readonly ?CarbonImmutable $expiresAt,
        public readonly ?string $sub,
    ) {}

    /**
     * @param  array<string, mixed>  $body
     */
    public static function fromVerdict(array $body): self
    {
        $scope = trim((string) ($body['scope'] ?? ''));

        return new self(
            active: (bool) ($body['active'] ?? false),
            tokenType: isset($body['token_type']) ? (string) $body['token_type'] : null,
            clientId: isset($body['client_id']) ? (string) $body['client_id'] : null,
            scopes: $scope === '' ? [] : preg_split('/\s+/', $scope) ?: [],
            expiresAt: isset($body['exp']) ? CarbonImmutable::createFromTimestampUTC((int) $body['exp']) : null,
            sub: isset($body['sub']) ? (string) $body['sub'] : null,
        );
    }
}
```

- [ ] **Step 5: Write the exceptions**

`src/Exceptions/RaccountException.php`:

```php
<?php

namespace Raccount\Sso\Exceptions;

use RuntimeException;

abstract class RaccountException extends RuntimeException
{
}
```

`src/Exceptions/ConfigurationInvalid.php`:

```php
<?php

namespace Raccount\Sso\Exceptions;

final class ConfigurationInvalid extends RaccountException
{
}
```

`src/Exceptions/RequestFailed.php`:

```php
<?php

namespace Raccount\Sso\Exceptions;

final class RequestFailed extends RaccountException
{
}
```

`src/Exceptions/RateLimited.php`:

```php
<?php

namespace Raccount\Sso\Exceptions;

final class RateLimited extends RaccountException
{
    public function __construct(
        string $message,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
    }
}
```

`src/Exceptions/TokenExchangeFailed.php`:

```php
<?php

namespace Raccount\Sso\Exceptions;

final class TokenExchangeFailed extends RaccountException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly string $errorDescription,
    ) {
        parent::__construct("RAccount token endpoint rejected the request: [{$errorCode}] {$errorDescription}");
    }
}
```

`src/Exceptions/InvalidGrant.php`:

```php
<?php

namespace Raccount\Sso\Exceptions;

/**
 * The refresh token (or authorization code) is expired, already used, or its
 * family was revoked. Per the RAccount contract this means: re-authenticate
 * the user. NEVER retry the same grant.
 */
final class InvalidGrant extends TokenExchangeFailed
{
    public function __construct(string $errorDescription = 'The grant is no longer valid.')
    {
        parent::__construct('invalid_grant', $errorDescription);
    }
}
```

`src/Exceptions/UserInfoFailed.php`:

```php
<?php

namespace Raccount\Sso\Exceptions;

final class UserInfoFailed extends RaccountException
{
    public function __construct(
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }
}
```

`src/Exceptions/AccountLinkageDenied.php`:

```php
<?php

namespace Raccount\Sso\Exceptions;

final class AccountLinkageDenied extends RaccountException
{
}
```

`src/Exceptions/EmailNotVerified.php`:

```php
<?php

namespace Raccount\Sso\Exceptions;

final class EmailNotVerified extends AccountLinkageDenied
{
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/DtoTest.php`
Expected: 5 passing tests.

- [ ] **Step 7: Commit**

```bash
git add src/config src/Client src/Exceptions tests/Unit/DtoTest.php
git commit -m "feat: configuration, DTOs, and exception hierarchy"
```

---

### Task 3: PKCE helper

**Files:**
- Create: `src/Flow/Pkce.php`
- Test: `tests/Unit/PkceTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `Raccount\Sso\Flow\Pkce` with `static verifier(): string` (43–128 chars, base64url of 64 random bytes) and `static challenge(string $verifier): string` (base64url of SHA-256, no padding). Used by Task 9 (`RedirectController`).

- [ ] **Step 1: Write the failing test**

`tests/Unit/PkceTest.php`:

```php
<?php

use Raccount\Sso\Flow\Pkce;

it('generates valid pkce verifiers', function (): void {
    for ($i = 0; $i < 5; $i++) {
        $verifier = Pkce::verifier();

        expect(strlen($verifier))->toBeGreaterThanOrEqual(43)
            ->and(strlen($verifier))->toBeLessThanOrEqual(128)
            ->and($verifier)->toMatch('/^[A-Za-z0-9_-]+$/');
    }
});

it('generates unique verifiers', function (): void {
    expect(Pkce::verifier())->not->toBe(Pkce::verifier());
});

it('derives the s256 challenge deterministically', function (): void {
    $challenge = Pkce::challenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk');

    expect($challenge)->toBe('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM')
        ->and($challenge)->toMatch('/^[A-Za-z0-9_-]+$/');
});
```

(The challenge vector above is the official RFC 7636 appendix B test vector.)

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/PkceTest.php`
Expected: FAIL — class `Raccount\Sso\Flow\Pkce` not found.

- [ ] **Step 3: Implement**

`src/Flow/Pkce.php`:

```php
<?php

namespace Raccount\Sso\Flow;

final class Pkce
{
    public static function verifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
    }

    public static function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/PkceTest.php`
Expected: 3 passing tests.

- [ ] **Step 5: Commit**

```bash
git add src/Flow tests/Unit/PkceTest.php
git commit -m "feat: pkce verifier and s256 challenge helper"
```

---

### Task 4: RaccountClient — transport, identity endpoints, error mapping

**Files:**
- Create: `src/Client/RaccountClient.php`
- Test: `tests/Feature/ClientTest.php`

**Interfaces:**
- Consumes: DTOs + exceptions (Task 2); config from Task 2 (not yet merged into the provider — merge it here, see Step 3).
- Produces: `Raccount\Sso\Client\RaccountClient` with public methods (all read config at call time — Octane-safe):
  - `ping(): bool` (throws `RequestFailed` when unreachable after retries)
  - `userinfo(string $accessToken): UserInfo` (throws `UserInfoFailed` on 401/403)
  - `introspect(string $token, string $hint = 'access_token'): IntrospectionResult`
  - `revoke(string $token, string $hint = 'refresh_token'): bool` (throws on OAuth errors — callers decide best-effort)
  - private transport contract reused by Task 5: `private send(Closure $attempt): Response` retrying connection errors and 5xx (up to `http.attempts`, default 3) with jittered backoff, throwing `RateLimited` on 429 (with `Retry-After`), `RequestFailed` when attempts are exhausted; `private http(): PendingRequest` enforcing non-empty HTTPS base URL (`ConfigurationInvalid` otherwise; http allowed only when `app.env === 'local'` AND `server.allow_insecure === true`); `private url(string $configKey): string`; `private assertOAuthResponse(Response $response): void` mapping 400/401 RFC 6749 bodies to `InvalidGrant`/`TokenExchangeFailed`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/ClientTest.php`:

```php
<?php

use Illuminate\Support\Facades\Http;
use Raccount\Sso\Client\RaccountClient;
use Raccount\Sso\Exceptions\ConfigurationInvalid;
use Raccount\Sso\Exceptions\RateLimited;
use Raccount\Sso\Exceptions\RequestFailed;
use Raccount\Sso\Exceptions\UserInfoFailed;

it('rejects an empty base url', function (): void {
    config()->set('raccount-sso.server.base_url', null);

    app(RaccountClient::class)->ping();
})->throws(ConfigurationInvalid::class);

it('rejects plain http outside local development', function (): void {
    config()->set('app.env', 'production');
    config()->set('raccount-sso.server.base_url', 'http://account.test');

    app(RaccountClient::class)->ping();
})->throws(ConfigurationInvalid::class);

it('allows plain http in local development when explicitly opted in', function (): void {
    config()->set('app.env', 'local');
    config()->set('raccount-sso.server.base_url', 'http://account.test');
    config()->set('raccount-sso.server.allow_insecure', true);
    Http::fake(['account.test/api/v1/ping' => Http::response(['status' => 'ok'])]);

    expect(app(RaccountClient::class)->ping())->toBeTrue();
});

it('pings the server', function (): void {
    Http::fake(['account.test/api/v1/ping' => Http::response(['status' => 'ok'])]);

    expect(app(RaccountClient::class)->ping())->toBeTrue();
});

it('parses userinfo claims', function (): void {
    Http::fake(['account.test/api/v1/userinfo' => Http::response([
        'sub' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
        'name' => 'Budi',
        'email' => 'budi@example.com',
        'email_verified' => true,
    ])]);

    $info = app(RaccountClient::class)->userinfo('token');

    expect($info->sub)->toBe('0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0')
        ->and($info->emailVerified)->toBeTrue();
});

it('maps userinfo problem details to an exception', function (): void {
    Http::fake(['account.test/api/v1/userinfo' => Http::response([
        'type' => 'https://raccount.reducates.id/problems/insufficient-scope',
        'title' => 'Insufficient scope',
        'status' => 403,
        'detail' => 'The access token does not carry a scope required by this endpoint.',
    ], 403)]);

    app(RaccountClient::class)->userinfo('token');
})->throws(UserInfoFailed::class);

it('introspects a token', function (): void {
    Http::fake(['account.test/oauth/introspect' => Http::response([
        'active' => true,
        'token_type' => 'access_token',
        'scope' => 'profile email',
    ])]);

    $result = app(RaccountClient::class)->introspect('token');

    expect($result->active)->toBeTrue()
        ->and($result->scopes)->toBe(['profile', 'email']);

    Http::assertSent(function ($request): bool {
        return str_ends_with($request->url(), '/oauth/introspect')
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('11111111-1111-1111-1111-111111111111:'.str_repeat('a', 64)));
    });
});

it('revokes a token and reports oauth errors', function (): void {
    Http::fake(['account.test/oauth/revoke' => Http::response(['error' => 'invalid_client'], 401)]);

    expect(app(RaccountClient::class)->revoke('token', 'refresh_token'))->toBeFalse();
});

it('retries server errors and then succeeds', function (): void {
    Http::fake([
        'account.test/api/v1/ping' => Http::sequence()
            ->push(['error' => 'error'], 500)
            ->push(['status' => 'ok']),
    ]);

    expect(app(RaccountClient::class)->ping())->toBeTrue();
});

it('gives up after the configured attempts', function (): void {
    config()->set('raccount-sso.http.attempts', 2);
    config()->set('raccount-sso.http.backoff_ms', 1);
    Http::fake(['account.test/api/v1/ping' => Http::response(['error' => 'error'], 500)]);

    app(RaccountClient::class)->ping();
})->throws(RequestFailed::class);

it('surfaces rate limits with the retry-after header', function (): void {
    Http::fake(['account.test/api/v1/userinfo' => Http::response(
        ['type' => 'https://raccount.reducates.id/problems/rate-limited', 'title' => 'Rate limited', 'status' => 429, 'detail' => 'Slow down.'],
        429,
        ['Retry-After' => '17'],
    )]);

    try {
        app(RaccountClient::class)->userinfo('token');
        $this->fail('RateLimited was not thrown.');
    } catch (RateLimited $exception) {
        expect($exception->retryAfterSeconds)->toBe(17);
    }
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/ClientTest.php`
Expected: FAIL — class `Raccount\Sso\Client\RaccountClient` not found.

- [ ] **Step 3: Implement the client and merge config in the provider**

Update `src/RaccountSsoServiceProvider.php` (merge the config so `config('raccount-sso.*')` resolves):

```php
<?php

namespace Raccount\Sso;

use Illuminate\Support\ServiceProvider;

final class RaccountSsoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/raccount-sso.php', 'raccount-sso');
    }
}
```

`src/Client/RaccountClient.php`:

```php
<?php

namespace Raccount\Sso\Client;

use Closure;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Raccount\Sso\Client\Dto\DirectoryPage;
use Raccount\Sso\Client\Dto\IntrospectionResult;
use Raccount\Sso\Client\Dto\TokenPair;
use Raccount\Sso\Client\Dto\UserInfo;
use Raccount\Sso\Exceptions\ConfigurationInvalid;
use Raccount\Sso\Exceptions\InvalidGrant;
use Raccount\Sso\Exceptions\RateLimited;
use Raccount\Sso\Exceptions\RequestFailed;
use Raccount\Sso\Exceptions\TokenExchangeFailed;
use Raccount\Sso\Exceptions\UserInfoFailed;

class RaccountClient
{
    public function __construct(
        private readonly Factory $factory,
    ) {}

    public function ping(): bool
    {
        $response = $this->send(
            fn (): \Illuminate\Http\Client\Response => $this->http()
                ->acceptJson()
                ->get($this->url('ping_path'))
        );

        return $response->status() === 200 && $response->json('status') === 'ok';
    }

    public function userinfo(string $accessToken): UserInfo
    {
        $response = $this->send(
            fn (): \Illuminate\Http\Client\Response => $this->http()
                ->withToken($accessToken)
                ->acceptJson()
                ->get($this->url('userinfo_path'))
        );

        if (in_array($response->status(), [401, 403], true)) {
            throw new UserInfoFailed(
                (string) ($response->json('detail') ?? 'The userinfo endpoint rejected the token.'),
                $response->status(),
            );
        }

        return UserInfo::fromClaimSet((array) $response->json());
    }

    public function introspect(string $token, string $hint = 'access_token'): IntrospectionResult
    {
        $response = $this->send(
            fn (): \Illuminate\Http\Client\Response => $this->http()
                ->withBasicAuth($this->clientId(), $this->clientSecret())
                ->asForm()
                ->post($this->url('introspect_path'), [
                    'token' => $token,
                    'token_type_hint' => $hint,
                ])
        );

        $this->assertOAuthResponse($response);

        return IntrospectionResult::fromVerdict((array) $response->json());
    }

    public function revoke(string $token, string $hint = 'refresh_token'): bool
    {
        $response = $this->send(
            fn (): \Illuminate\Http\Client\Response => $this->http()
                ->withBasicAuth($this->clientId(), $this->clientSecret())
                ->asForm()
                ->post($this->url('revoke_path'), [
                    'token' => $token,
                    'token_type_hint' => $hint,
                ])
        );

        if (in_array($response->status(), [400, 401], true)) {
            return false;
        }

        return true;
    }

    // ------------------------------------------------------------------
    // Transport
    // ------------------------------------------------------------------

    /**
     * Retry connection errors and 5xx responses with jittered backoff.
     * Never retries 4xx (including invalid_grant) — those are deterministic.
     *
     * @param  Closure(): Response  $attempt
     */
    private function send(Closure $attempt): Response
    {
        $maxAttempts = max(1, (int) config('raccount-sso.http.attempts', 3));
        $backoffMs = max(0, (int) config('raccount-sso.http.backoff_ms', 200));

        for ($attemptNumber = 1;; $attemptNumber++) {
            try {
                $response = $attempt();
            } catch (ConnectionException $exception) {
                if ($attemptNumber >= $maxAttempts) {
                    throw new RequestFailed(
                        'Could not reach the RAccount server: '.$exception->getMessage(),
                        0,
                        $exception,
                    );
                }
                usleep(($backoffMs * $attemptNumber + random_int(0, 50)) * 1000);
                continue;
            }

            if ($response->status() === 429) {
                $retryAfter = $response->header('Retry-After');

                throw new RateLimited(
                    'RAccount rate limited the request; back off before retrying.',
                    is_numeric($retryAfter) ? (int) $retryAfter : null,
                );
            }

            if (! $response->serverError()) {
                return $response;
            }

            if ($attemptNumber >= $maxAttempts) {
                throw new RequestFailed("RAccount server error (HTTP {$response->status()}).");
            }

            usleep(($backoffMs * $attemptNumber + random_int(0, 50)) * 1000);
        }
    }

    private function http(): PendingRequest
    {
        return $this->factory
            ->timeout((int) config('raccount-sso.http.timeout', 10))
            ->connectTimeout((int) config('raccount-sso.http.connect_timeout', 10));
    }

    private function url(string $pathConfigKey): string
    {
        $base = rtrim((string) config('raccount-sso.server.base_url'), '/');

        if ($base === '') {
            throw new ConfigurationInvalid('raccount-sso.server.base_url is not configured.');
        }

        $scheme = (string) (parse_url($base, PHP_URL_SCHEME) ?: 'https');
        $allowInsecure = config('app.env') === 'local'
            && config('raccount-sso.server.allow_insecure') === true;

        if ($scheme !== 'https' && ! $allowInsecure) {
            throw new ConfigurationInvalid(
                "raccount-sso.server.base_url must use HTTPS (got `{$scheme}://`). Plain HTTP is only allowed in local development with server.allow_insecure enabled.",
            );
        }

        return $base.'/'.ltrim((string) config("raccount-sso.server.{$pathConfigKey}"), '/');
    }

    /**
     * Map RFC 6749 error bodies on /oauth/* to typed exceptions.
     */
    private function assertOAuthResponse(Response $response): void
    {
        if (! in_array($response->status(), [400, 401], true)) {
            return;
        }

        $error = (string) ($response->json('error') ?? 'invalid_request');
        $description = (string) ($response->json('error_description') ?? 'The token endpoint rejected the request.');

        if ($error === 'invalid_grant') {
            throw new InvalidGrant($description);
        }

        throw new TokenExchangeFailed($error, $description);
    }

    private function clientId(): string
    {
        $id = (string) config('raccount-sso.client.id');

        if ($id === '') {
            throw new ConfigurationInvalid('raccount-sso.client.id is not configured.');
        }

        return $id;
    }

    private function clientSecret(): string
    {
        $secret = (string) config('raccount-sso.client.secret');

        if ($secret === '') {
            throw new ConfigurationInvalid('raccount-sso.client.secret is not configured.');
        }

        return $secret;
    }
}
```

Note: `ping()` returning `false` vs throwing — `send()` throws on 5xx/connection; a 200-with-wrong-body returns `false`. Keep as implemented.

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/ClientTest.php`
Expected: 11 passing tests. Also run the whole suite: `vendor/bin/pest`.

- [ ] **Step 5: Commit**

```bash
git add src/Client/RaccountClient.php src/RaccountSsoServiceProvider.php tests/Feature/ClientTest.php
git commit -m "feat: raccount http client with retry, rate-limit and error mapping"
```

---

### Task 5: RaccountClient — OAuth token operations, authorization URL, facade

**Files:**
- Modify: `src/Client/RaccountClient.php` (add methods)
- Create: `src/Facades/Raccount.php`
- Test: `tests/Feature/ClientOAuthTest.php`

**Interfaces:**
- Consumes: `RaccountClient` transport (Task 4).
- Produces:
  - `RaccountClient::authorizationUrl(string $state, string $codeChallenge, ?string $prompt = null): string`
  - `RaccountClient::exchangeCode(string $code, string $codeVerifier): TokenPair`
  - `RaccountClient::refresh(string $refreshToken): TokenPair` (throws `InvalidGrant`)
  - `RaccountClient::clientCredentialsToken(string $scope): TokenPair`
  - `RaccountClient::directoryPage(string $accessToken, ?string $cursor = null, ?DateTimeInterface $updatedSince = null, ?int $limit = null): DirectoryPage` (throws `RequestFailed` on non-2xx)
  - Facade `Raccount\Sso\Facades\Raccount` (accessor `raccount-sso.client`); container singleton `raccount-sso.client` → `RaccountClient` (registered in the provider in this task).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/ClientOAuthTest.php`:

```php
<?php

use Illuminate\Support\Facades\Http;
use Raccount\Sso\Client\RaccountClient;
use Raccount\Sso\Exceptions\InvalidGrant;
use Raccount\Sso\Facades\Raccount;
use Raccount\Sso\Exceptions\TokenExchangeFailed;

it('builds the authorization url with pkce and scopes', function (): void {
    $url = app(RaccountClient::class)->authorizationUrl('state-123', 'challenge-abc');

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($url)->toStartWith('https://account.test/oauth/authorize')
        ->and($query)->toMatchArray([
            'response_type' => 'code',
            'client_id' => '11111111-1111-1111-1111-111111111111',
            'redirect_uri' => 'https://app.test/raccount/callback',
            'scope' => 'profile email',
            'state' => 'state-123',
            'code_challenge' => 'challenge-abc',
            'code_challenge_method' => 'S256',
        ])
        ->and($query)->not->toHaveKey('prompt');
});

it('includes the prompt parameter when configured', function (): void {
    $url = app(RaccountClient::class)->authorizationUrl('s', 'c', 'consent');

    expect($url)->toContain('prompt=consent');
});

it('exchanges an authorization code for a token pair', function (): void {
    Http::fake(['account.test/oauth/token' => Http::response([
        'token_type' => 'Bearer',
        'expires_in' => 900,
        'access_token' => 'at',
        'refresh_token' => 'rt',
        'scope' => 'profile email',
    ])]);

    $pair = app(RaccountClient::class)->exchangeCode('the-code', 'the-verifier');

    expect($pair->accessToken)->toBe('at')
        ->and($pair->refreshToken)->toBe('rt');

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return str_ends_with($request->url(), '/oauth/token')
            && $body['grant_type'] === 'authorization_code'
            && $body['code'] === 'the-code'
            && $body['code_verifier'] === 'the-verifier'
            && $body['redirect_uri'] === 'https://app.test/raccount/callback'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('11111111-1111-1111-1111-111111111111:'.str_repeat('a', 64)));
    });
});

it('refreshes a token and maps invalid_grant to the typed exception', function (): void {
    Http::fake(['account.test/oauth/token' => Http::response([
        'error' => 'invalid_grant',
        'error_description' => 'The refresh token has been revoked.',
    ], 400)]);

    app(RaccountClient::class)->refresh('stale-token');
})->throws(InvalidGrant::class);

it('maps other oauth errors to token exchange failures', function (): void {
    Http::fake(['account.test/oauth/token' => Http::response([
        'error' => 'invalid_client',
        'error_description' => 'Client authentication failed.',
    ], 401)]);

    app(RaccountClient::class)->clientCredentialsToken('sync:read');
})->throws(TokenExchangeFailed::class);

it('obtains a machine token via client credentials', function (): void {
    Http::fake(['account.test/oauth/token' => Http::response([
        'token_type' => 'Bearer',
        'expires_in' => 3600,
        'access_token' => 'm2m',
        'scope' => 'sync:read',
    ])]);

    $pair = app(RaccountClient::class)->clientCredentialsToken('sync:read');

    expect($pair->accessToken)->toBe('m2m');

    Http::assertSent(fn ($request): bool => $request->data()['grant_type'] === 'client_credentials'
        && $request->data()['scope'] === 'sync:read');
});

it('paginates the directory', function (): void {
    Http::fake(['account.test/api/v1/directory/users*' => Http::response([
        'data' => [[
            'sub' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
            'name' => 'Budi',
            'email' => 'budi@example.com',
            'email_verified' => true,
            'status' => 'active',
            'updated_at' => '2026-09-07T00:00:00Z',
        ]],
        'next_cursor' => 'CUR1',
    ])]);

    $page = app(RaccountClient::class)->directoryPage('m2m-token', null, null, 200);

    expect($page->data)->toHaveCount(1)
        ->and($page->nextCursor)->toBe('CUR1');
});

it('resolves the client through the facade', function (): void {
    Http::fake(['account.test/api/v1/ping' => Http::response(['status' => 'ok'])]);

    expect(Raccount::ping())->toBeTrue();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/ClientOAuthTest.php`
Expected: FAIL — methods and facade do not exist.

- [ ] **Step 3: Implement**

Add to `src/Client/RaccountClient.php` (inside the class, after `revoke()`):

```php
    public function authorizationUrl(string $state, string $codeChallenge, ?string $prompt = null): string
    {
        $query = array_filter([
            'response_type' => 'code',
            'client_id' => $this->clientId(),
            'redirect_uri' => (string) config('raccount-sso.client.redirect_uri'),
            'scope' => implode(' ', (array) config('raccount-sso.scopes', ['profile', 'email'])),
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'prompt' => $prompt,
        ], static fn ($value): bool => $value !== null && $value !== '');

        return $this->url('authorize_path').'?'.http_build_query($query);
    }

    public function exchangeCode(string $code, string $codeVerifier): TokenPair
    {
        $response = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'redirect_uri' => (string) config('raccount-sso.client.redirect_uri'),
            'code' => $code,
            'code_verifier' => $codeVerifier,
        ]);

        return TokenPair::fromTokenResponse((array) $response->json());
    }

    public function refresh(string $refreshToken): TokenPair
    {
        $response = $this->tokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        return TokenPair::fromTokenResponse((array) $response->json());
    }

    public function clientCredentialsToken(string $scope): TokenPair
    {
        $response = $this->tokenRequest([
            'grant_type' => 'client_credentials',
            'scope' => $scope,
        ]);

        return TokenPair::fromTokenResponse((array) $response->json());
    }

    public function directoryPage(
        string $accessToken,
        ?string $cursor = null,
        ?DateTimeInterface $updatedSince = null,
        ?int $limit = null,
    ): DirectoryPage {
        $response = $this->send(
            fn (): \Illuminate\Http\Client\Response => $this->http()
                ->withToken($accessToken)
                ->acceptJson()
                ->get($this->url('directory_path'), array_filter([
                    'cursor' => $cursor,
                    'updated_since' => $updatedSince?->format(DateTimeInterface::ATOM),
                    'limit' => $limit,
                ], static fn ($value): bool => $value !== null && $value !== ''))
        );

        if (! $response->successful()) {
            throw new RequestFailed("RAccount directory request failed (HTTP {$response->status()}).");
        }

        return DirectoryPage::fromResponse((array) $response->json());
    }

    /**
     * @param  array<string, string>  $form
     */
    private function tokenRequest(array $form): Response
    {
        $response = $this->send(
            fn (): \Illuminate\Http\Client\Response => $this->http()
                ->withBasicAuth($this->clientId(), $this->clientSecret())
                ->asForm()
                ->post($this->url('token_path'), $form)
        );

        $this->assertOAuthResponse($response);

        return $response;
    }
```

Also add `use DateTimeInterface;` to the imports of the file (it is already listed in Task 4's imports — verify it is present).

`src/Facades/Raccount.php`:

```php
<?php

namespace Raccount\Sso\Facades;

use Illuminate\Support\Facades\Facade;
use Raccount\Sso\Client\Dto\DirectoryPage;
use Raccount\Sso\Client\Dto\IntrospectionResult;
use Raccount\Sso\Client\Dto\TokenPair;
use Raccount\Sso\Client\Dto\UserInfo;

/**
 * @method static string authorizationUrl(string $state, string $codeChallenge, ?string $prompt = null)
 * @method static TokenPair exchangeCode(string $code, string $codeVerifier)
 * @method static TokenPair refresh(string $refreshToken)
 * @method static TokenPair clientCredentialsToken(string $scope)
 * @method static UserInfo userinfo(string $accessToken)
 * @method static IntrospectionResult introspect(string $token, string $hint = 'access_token')
 * @method static bool revoke(string $token, string $hint = 'refresh_token')
 * @method static DirectoryPage directoryPage(string $accessToken, ?string $cursor = null, ?\DateTimeInterface $updatedSince = null, ?int $limit = null)
 * @method static bool ping()
 *
 * @see \Raccount\Sso\Client\RaccountClient
 */
final class Raccount extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'raccount-sso.client';
    }
}
```

Update `src/RaccountSsoServiceProvider.php` `register()`:

```php
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/raccount-sso.php', 'raccount-sso');

        $this->app->singleton('raccount-sso.client', static fn ($app): \Raccount\Sso\Client\RaccountClient => new \Raccount\Sso\Client\RaccountClient(
            $app->make(\Illuminate\Http\Client\Factory::class),
        ));
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest`
Expected: all previous tests plus the 8 new ones pass.

- [ ] **Step 5: Commit**

```bash
git add src/Client/RaccountClient.php src/Facades src/RaccountSsoServiceProvider.php tests/Feature/ClientOAuthTest.php
git commit -m "feat: oauth token operations, authorization url, and raccount facade"
```

---

### Task 6: Models, migrations, and publishing

**Files:**
- Create: `src/Models/RaccountAccount.php`, `src/Models/RaccountWebhookEvent.php`
- Create: `database/migrations/2026_09_07_000001_create_raccount_accounts_table.php`, `database/migrations/2026_09_07_000002_create_raccount_webhook_events_table.php`
- Modify: `src/RaccountSsoServiceProvider.php` (publishing tags)
- Test: `tests/Feature/ModelsTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces (used by Tasks 7–13):
  - `Raccount\Sso\Models\RaccountAccount` (table `raccount_accounts`): constants `STATUS_ACTIVE = 'active'`, `STATUS_SUSPENDED = 'suspended'`, `STATUS_DELETED = 'deleted'`; encrypted casts for `access_token`/`refresh_token`; `user(): MorphTo`; `scopeForUser(Builder $query, Authenticatable $user): Builder`; static `morphTypeFor(Authenticatable $user): string`; `isActive(): bool`.
  - `Raccount\Sso\Models\RaccountWebhookEvent` (table `raccount_webhook_events`): casts `payload => array`, `received_at => datetime`, `processed_at => datetime`.
  - Publish tags: `raccount-sso-config`, `raccount-sso-migrations`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/ModelsTest.php`:

```php
<?php

use Illuminate\Support\Facades\DB;
use Raccount\Sso\Models\RaccountAccount;
use Raccount\Sso\Models\RaccountWebhookEvent;
use Raccount\Sso\Tests\Fixtures\User;

it('encrypts tokens at rest', function (): void {
    $user = User::create(['name' => 'Budi', 'email' => 'budi@example.com']);

    $account = RaccountAccount::query()->create([
        'raccount_sub' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
        'user_type' => RaccountAccount::morphTypeFor($user),
        'user_id' => $user->getAuthIdentifier(),
        'email' => 'budi@example.com',
        'name' => 'Budi',
        'status' => RaccountAccount::STATUS_ACTIVE,
        'access_token' => 'secret-access-token',
        'refresh_token' => 'secret-refresh-token',
        'scopes' => ['profile', 'email'],
    ]);

    expect($account->access_token)->toBe('secret-access-token')
        ->and($account->refresh_token)->toBe('secret-refresh-token')
        ->and($account->isActive())->toBeTrue();

    $raw = DB::table('raccount_accounts')->where('id', $account->id)->first();

    expect($raw->access_token)->not->toBe('secret-access-token')
        ->and($raw->refresh_token)->not->toBe('secret-refresh-token');
});

it('relates an account to its morphed user', function (): void {
    $user = User::create(['name' => 'Budi', 'email' => 'budi@example.com']);

    RaccountAccount::query()->create([
        'raccount_sub' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
        'user_type' => RaccountAccount::morphTypeFor($user),
        'user_id' => $user->getAuthIdentifier(),
        'status' => RaccountAccount::STATUS_ACTIVE,
    ]);

    $account = RaccountAccount::query()->forUser($user)->first();

    expect($account->user)->toBeInstanceOf(User::class)
        ->and($account->user->id)->toBe($user->id);
});

it('stores webhook event payloads as json', function (): void {
    $event = RaccountWebhookEvent::query()->create([
        'event_id' => '01JABCDEFGHJKMNPQRSTVWXYZ',
        'event_type' => 'user.updated',
        'payload' => ['data' => ['sub' => 'x']],
        'received_at' => now(),
    ]);

    expect($event->payload)->toBe(['data' => ['sub' => 'x']])
        ->and($event->processed_at)->toBeNull();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/ModelsTest.php`
Expected: FAIL — models not found / tables missing.

- [ ] **Step 3: Write migrations and models**

`database/migrations/2026_09_07_000001_create_raccount_accounts_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raccount_accounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->uuid('raccount_sub')->unique();
            $table->string('user_type');
            $table->string('user_id');
            $table->index(['user_type', 'user_id']);
            $table->string('email')->nullable()->index();
            $table->string('name')->nullable();
            $table->text('picture_url')->nullable();
            $table->json('scopes')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('access_expires_at', 3)->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamp('last_login_at', 3)->nullable();
            $table->timestamps(3);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raccount_accounts');
    }
};
```

`database/migrations/2026_09_07_000002_create_raccount_webhook_events_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raccount_webhook_events', function (Blueprint $table): void {
            $table->ulid('event_id')->primary();
            $table->string('event_type', 64);
            $table->json('payload')->nullable();
            $table->timestamp('received_at', 3);
            $table->timestamp('processed_at', 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raccount_webhook_events');
    }
};
```

`src/Models/RaccountAccount.php`:

```php
<?php

namespace Raccount\Sso\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class RaccountAccount extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_DELETED = 'deleted';

    protected $table = 'raccount_accounts';

    protected $guarded = [];

    protected $casts = [
        'scopes' => 'array',
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'access_expires_at' => 'datetime',
        'last_login_at' => 'datetime',
    ];

    public function user(): MorphTo
    {
        return $this->morphTo();
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function scopeForUser(Builder $query, Authenticatable $user): Builder
    {
        return $query
            ->where('user_type', self::morphTypeFor($user))
            ->where('user_id', $user->getAuthIdentifier());
    }

    public static function morphTypeFor(Authenticatable $user): string
    {
        return $user instanceof Model ? $user->getMorphClass() : $user::class;
    }
}
```

`src/Models/RaccountWebhookEvent.php`:

```php
<?php

namespace Raccount\Sso\Models;

use Illuminate\Database\Eloquent\Model;

class RaccountWebhookEvent extends Model
{
    protected $table = 'raccount_webhook_events';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public $timestamps = false;
}
```

Update `src/RaccountSsoServiceProvider.php` — add a `boot()` method:

```php
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/config/raccount-sso.php' => config_path('raccount-sso.php'),
        ], 'raccount-sso-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'raccount-sso-migrations');
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest`
Expected: all tests pass (the `TestCase` already loads `database/migrations`).

- [ ] **Step 5: Commit**

```bash
git add database src/Models src/RaccountSsoServiceProvider.php tests/Feature/ModelsTest.php
git commit -m "feat: raccount account and webhook event models with encrypted token casts"
```

---

### Task 7: User resolution (contract + default resolver)

**Files:**
- Create: `src/Contracts/UserResolver.php`, `src/Resolvers/DefaultUserResolver.php`
- Modify: `src/RaccountSsoServiceProvider.php` (bind the resolver from config)
- Test: `tests/Feature/ResolverTest.php`

**Interfaces:**
- Consumes: `UserInfo` (Task 2), `RaccountAccount` (Task 6).
- Produces:
  - `Raccount\Sso\Contracts\UserResolver::resolve(UserInfo $userinfo): Authenticatable` (throws `AccountLinkageDenied` / `EmailNotVerified`).
  - `DefaultUserResolver` resolution order: (1) existing link by `raccount_sub` → active status required, stale link (missing user) is deleted and flow continues; (2) `require_verified_email` gate; (3) email match → link if `auto_link_verified_email`, else deny; (4) JIT provisioning via `user.model` + `user.attributes` map; (5) JIT with email absent → create with mapped attributes that are non-null. All inside `DB::transaction`.
  - Container binding: `$this->app->bind(UserResolver::class, config value 'raccount-sso.user.resolver')`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/ResolverTest.php`:

```php
<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Raccount\Sso\Client\Dto\UserInfo;
use Raccount\Sso\Contracts\UserResolver;
use Raccount\Sso\Exceptions\AccountLinkageDenied;
use Raccount\Sso\Exceptions\EmailNotVerified;
use Raccount\Sso\Models\RaccountAccount;
use Raccount\Sso\Tests\Fixtures\User;

function resolveUser(UserInfo $info): Authenticatable
{
    return app(UserResolver::class)->resolve($info);
}

function userInfo(array $overrides = []): UserInfo
{
    return UserInfo::fromClaimSet(array_merge([
        'sub' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
        'name' => 'Budi Santoso',
        'email' => 'budi@example.com',
        'email_verified' => true,
    ], $overrides));
}

it('returns the linked user for an existing sub and refreshes the snapshot', function (): void {
    $user = User::create(['name' => 'Old Name', 'email' => 'budi@example.com']);
    RaccountAccount::query()->create([
        'raccount_sub' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
        'user_type' => RaccountAccount::morphTypeFor($user),
        'user_id' => $user->getAuthIdentifier(),
        'status' => RaccountAccount::STATUS_ACTIVE,
        'name' => 'Old Name',
    ]);

    $resolved = resolveUser(userInfo(['name' => 'New Name']));

    expect($resolved->id)->toBe($user->id);

    $account = RaccountAccount::query()->where('raccount_sub', '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0')->first();

    expect($account->name)->toBe('New Name')
        ->and($account->email)->toBe('budi@example.com');
});

it('denies login for a suspended linked account', function (): void {
    $user = User::create(['name' => 'Budi', 'email' => 'budi@example.com']);
    RaccountAccount::query()->create([
        'raccount_sub' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
        'user_type' => RaccountAccount::morphTypeFor($user),
        'user_id' => $user->getAuthIdentifier(),
        'status' => RaccountAccount::STATUS_SUSPENDED,
    ]);

    resolveUser(userInfo());
})->throws(AccountLinkageDenied::class);

it('removes a stale link whose local user no longer exists', function (): void {
    RaccountAccount::query()->create([
        'raccount_sub' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
        'user_type' => (new User)->getMorphClass(),
        'user_id' => 99999,
        'status' => RaccountAccount::STATUS_ACTIVE,
    ]);

    $resolved = resolveUser(userInfo());

    expect($resolved)->toBeInstanceOf(User::class)
        ->and($resolved->email)->toBe('budi@example.com')
        ->and(RaccountAccount::query()->where('user_id', 99999)->exists())->toBeFalse();
});

it('rejects an unverified email when required', function (): void {
    resolveUser(userInfo(['email_verified' => false]));
})->throws(EmailNotVerified::class);

it('rejects a missing email verification claim when required', function (): void {
    resolveUser(userInfo(['email' => null, 'email_verified' => null]));
})->throws(EmailNotVerified::class);

it('links an existing local user with a matching verified email', function (): void {
    $user = User::create(['name' => 'Budi', 'email' => 'BUDI@example.com']);

    $resolved = resolveUser(userInfo(['email' => 'budi@example.com']));

    expect($resolved->id)->toBe($user->id);

    expect(RaccountAccount::query()
        ->where('raccount_sub', '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0')
        ->where('user_id', $user->id)
        ->exists())->toBeTrue();
});

it('denies linking an existing email when auto-link is disabled', function (): void {
    config()->set('raccount-sso.user.auto_link_verified_email', false);
    User::create(['name' => 'Budi', 'email' => 'budi@example.com']);

    resolveUser(userInfo());
})->throws(AccountLinkageDenied::class);

it('provisions a new local user with mapped attributes', function (): void {
    $resolved = resolveUser(userInfo());

    expect($resolved)->toBeInstanceOf(User::class)
        ->and($resolved->name)->toBe('Budi Santoso')
        ->and($resolved->email)->toBe('budi@example.com')
        ->and(RaccountAccount::query()->where('raccount_sub', $resolved ? '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0' : '')->exists())->toBeTrue();
});

it('honours a custom attribute map', function (): void {
    config()->set('raccount-sso.user.attributes', ['name' => 'name']);

    $resolved = resolveUser(userInfo());

    expect($resolved->name)->toBe('Budi Santoso')
        ->and($resolved->email)->toBeNull();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/ResolverTest.php`
Expected: FAIL — contract/class missing.

- [ ] **Step 3: Implement**

`src/Contracts/UserResolver.php`:

```php
<?php

namespace Raccount\Sso\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Raccount\Sso\Client\Dto\UserInfo;
use Raccount\Sso\Exceptions\AccountLinkageDenied;

interface UserResolver
{
    /**
     * Map a verified RAccount identity onto a local user.
     *
     * @throws AccountLinkageDenied
     */
    public function resolve(UserInfo $userinfo): Authenticatable;
}
```

`src/Resolvers/DefaultUserResolver.php`:

```php
<?php

namespace Raccount\Sso\Resolvers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Raccount\Sso\Client\Dto\UserInfo;
use Raccount\Sso\Contracts\UserResolver;
use Raccount\Sso\Exceptions\AccountLinkageDenied;
use Raccount\Sso\Exceptions\EmailNotVerified;
use Raccount\Sso\Models\RaccountAccount;

class DefaultUserResolver implements UserResolver
{
    public function resolve(UserInfo $userinfo): Authenticatable
    {
        return DB::transaction(function () use ($userinfo): Authenticatable {
            $account = RaccountAccount::query()
                ->where('raccount_sub', $userinfo->sub)
                ->lockForUpdate()
                ->first();

            if ($account instanceof RaccountAccount) {
                $user = $account->user;

                if ($user === null) {
                    // Stale link (local user deleted) — start over below.
                    $account->delete();
                } else {
                    if (! $account->isActive()) {
                        throw new AccountLinkageDenied(
                            "The RAccount identity {$userinfo->sub} is {$account->status} on this application.",
                        );
                    }

                    $account->forceFill($this->snapshot($userinfo))->save();

                    return $user;
                }
            }

            if (config('raccount-sso.user.require_verified_email') && $userinfo->emailVerified !== true) {
                throw new EmailNotVerified(
                    'The RAccount identity has no verified email address; cannot link or provision an account.',
                );
            }

            if ($userinfo->email !== null) {
                $existing = $this->findExistingUser($userinfo->email);

                if ($existing !== null) {
                    if (config('raccount-sso.user.auto_link_verified_email') !== true) {
                        throw new AccountLinkageDenied(
                            "A local account already exists for {$userinfo->email}; automatic linking is disabled.",
                        );
                    }

                    $this->createAccount($existing, $userinfo);

                    return $existing;
                }
            }

            $user = $this->createLocalUser($userinfo);
            $this->createAccount($user, $userinfo);

            return $user;
        });
    }

    /**
     * @return array<string, string|null>
     */
    private function snapshot(UserInfo $userinfo): array
    {
        return [
            'name' => $userinfo->name,
            'email' => $userinfo->email,
            'picture_url' => $userinfo->picture,
        ];
    }

    private function createAccount(Authenticatable $user, UserInfo $userinfo): RaccountAccount
    {
        return RaccountAccount::query()->updateOrCreate(
            ['raccount_sub' => $userinfo->sub],
            array_merge($this->snapshot($userinfo), [
                'user_type' => RaccountAccount::morphTypeFor($user),
                'user_id' => $user->getAuthIdentifier(),
                'status' => RaccountAccount::STATUS_ACTIVE,
            ]),
        );
    }

    private function findExistingUser(string $email): ?Authenticatable
    {
        $model = (string) config('raccount-sso.user.model');
        $column = (string) config('raccount-sso.user.email_column', 'email');

        /** @var Authenticatable|null $user */
        $user = $model::query()
            ->whereRaw("LOWER({$column}) = ?", [mb_strtolower($email)])
            ->first();

        return $user;
    }

    private function createLocalUser(UserInfo $userinfo): Authenticatable
    {
        $model = (string) config('raccount-sso.user.model');

        $attributes = [];
        foreach ((array) config('raccount-sso.user.attributes', ['name' => 'name', 'email' => 'email']) as $column => $property) {
            $value = $userinfo->{$property} ?? null;
            if ($value !== null) {
                $attributes[$column] = $value;
            }
        }

        /** @var Authenticatable $user */
        $user = $model::create($attributes);

        return $user;
    }
}
```

Update `src/RaccountSsoServiceProvider.php` `register()` — append:

```php
        $this->app->bind(
            \Raccount\Sso\Contracts\UserResolver::class,
            (string) config('raccount-sso.user.resolver', \Raccount\Sso\Resolvers\DefaultUserResolver::class),
        );
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest`
Expected: all tests pass. If `phpstan` complains about `$model::create()` on a string class, silence precisely with a `@var` annotation as shown above — do not widen types.

- [ ] **Step 5: Commit**

```bash
git add src/Contracts src/Resolvers src/RaccountSsoServiceProvider.php tests/Feature/ResolverTest.php
git commit -m "feat: user resolver with verified-email linking and jit provisioning"
```

---

### Task 8: TokenService (persistence, rotation, revocation)

**Files:**
- Create: `src/Tokens/TokenService.php`
- Modify: `src/RaccountSsoServiceProvider.php` (singleton binding)
- Test: `tests/Feature/TokenServiceTest.php`

**Interfaces:**
- Consumes: `RaccountClient::refresh/revoke` (Tasks 4–5), `RaccountAccount` (Task 6).
- Produces: `Raccount\Sso\Tokens\TokenService` (container singleton):
  - `storeFor(Authenticatable $user, UserInfo $userinfo, TokenPair $tokens): RaccountAccount` — upserts by `raccount_sub`, sets morph, snapshot, encrypted tokens, `access_expires_at`, `scopes`, `status active`, `last_login_at now()`.
  - `refresh(RaccountAccount $account): TokenPair` — rotates: stores the NEW pair transactionally; on `InvalidGrant` clears all token columns then rethrows (never retries).
  - `revokeAll(RaccountAccount $account): void` — best-effort `revoke()` for refresh then access token; catches `RaccountException`, logs a warning; clears token columns.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/TokenServiceTest.php`:

```php
<?php

use Illuminate\Support\Facades\Http;
use Raccount\Sso\Client\Dto\TokenPair;
use Raccount\Sso\Client\Dto\UserInfo;
use Raccount\Sso\Exceptions\InvalidGrant;
use Raccount\Sso\Models\RaccountAccount;
use Raccount\Sso\Tests\Fixtures\User;
use Raccount\Sso\Tokens\TokenService;

function tokenPair(string $access = 'at', string $refresh = 'rt'): TokenPair
{
    return new TokenPair($access, $refresh, 900, ['profile', 'email'], now()->toImmutable());
}

function userInfoForTokens(): UserInfo
{
    return UserInfo::fromClaimSet([
        'sub' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
        'name' => 'Budi',
        'email' => 'budi@example.com',
        'email_verified' => true,
    ]);
}

it('stores tokens for a user with snapshot and expiry', function (): void {
    $user = User::create(['name' => 'Budi', 'email' => 'budi@example.com']);

    $account = app(TokenService::class)->storeFor($user, userInfoForTokens(), tokenPair());

    expect($account->access_token)->toBe('at')
        ->and($account->refresh_token)->toBe('rt')
        ->and($account->access_expires_at)->not->toBeNull()
        ->and($account->scopes)->toBe(['profile', 'email'])
        ->and($account->status)->toBe(RaccountAccount::STATUS_ACTIVE)
        ->and($account->last_login_at)->not->toBeNull()
        ->and($account->user_id)->toBe($user->id);
});

it('rotates refresh tokens and persists the new pair', function (): void {
    $user = User::create(['name' => 'Budi', 'email' => 'budi@example.com']);
    $account = app(TokenService::class)->storeFor($user, userInfoForTokens(), tokenPair('old-at', 'old-rt'));

    Http::fake(['account.test/oauth/token' => Http::response([
        'token_type' => 'Bearer',
        'expires_in' => 900,
        'access_token' => 'new-at',
        'refresh_token' => 'new-rt',
        'scope' => 'profile email',
    ])]);

    $fresh = app(TokenService::class)->refresh($account);

    expect($fresh->accessToken)->toBe('new-at');

    $account->refresh();

    expect($account->access_token)->toBe('new-at')
        ->and($account->refresh_token)->toBe('new-rt');
});

it('clears stored tokens when the refresh grant is invalid', function (): void {
    $user = User::create(['name' => 'Budi', 'email' => 'budi@example.com']);
    $account = app(TokenService::class)->storeFor($user, userInfoForTokens(), tokenPair('old-at', 'old-rt'));

    Http::fake(['account.test/oauth/token' => Http::response([
        'error' => 'invalid_grant',
        'error_description' => 'The refresh token has been revoked.',
    ], 400)]);

    try {
        app(TokenService::class)->refresh($account);
        $this->fail('InvalidGrant was not thrown.');
    } catch (InvalidGrant) {
        $account->refresh();

        expect($account->access_token)->toBeNull()
            ->and($account->refresh_token)->toBeNull()
            ->and($account->access_expires_at)->toBeNull();
    }
});

it('revokes both tokens best-effort and clears them locally', function (): void {
    $user = User::create(['name' => 'Budi', 'email' => 'budi@example.com']);
    $account = app(TokenService::class)->storeFor($user, userInfoForTokens(), tokenPair());

    Http::fake(['account.test/oauth/revoke' => Http::response(['error' => 'invalid_client'], 401)]);

    app(TokenService::class)->revokeAll($account);

    $account->refresh();

    expect($account->access_token)->toBeNull()
        ->and($account->refresh_token)->toBeNull();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/TokenServiceTest.php`
Expected: FAIL — `TokenService` not found.

- [ ] **Step 3: Implement**

`src/Tokens/TokenService.php`:

```php
<?php

namespace Raccount\Sso\Tokens;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Raccount\Sso\Client\Dto\TokenPair;
use Raccount\Sso\Client\Dto\UserInfo;
use Raccount\Sso\Client\RaccountClient;
use Raccount\Sso\Exceptions\InvalidGrant;
use Raccount\Sso\Exceptions\RaccountException;
use Raccount\Sso\Models\RaccountAccount;

class TokenService
{
    public function __construct(
        private readonly RaccountClient $client,
    ) {}

    public function storeFor(Authenticatable $user, UserInfo $userinfo, TokenPair $tokens): RaccountAccount
    {
        return DB::transaction(function () use ($user, $userinfo, $tokens): RaccountAccount {
            /** @var RaccountAccount $account */
            $account = RaccountAccount::query()->updateOrCreate(
                ['raccount_sub' => $userinfo->sub],
                [
                    'user_type' => RaccountAccount::morphTypeFor($user),
                    'user_id' => $user->getAuthIdentifier(),
                    'email' => $userinfo->email,
                    'name' => $userinfo->name,
                    'picture_url' => $userinfo->picture,
                    'scopes' => $tokens->scopes,
                    'access_token' => $tokens->accessToken,
                    'refresh_token' => $tokens->refreshToken,
                    'access_expires_at' => $tokens->expiresAt(),
                    'status' => RaccountAccount::STATUS_ACTIVE,
                    'last_login_at' => now(),
                ],
            );

            return $account;
        });
    }

    /**
     * Rotate the refresh token. The server invalidates the old refresh token
     * on every use; an invalid_grant means the family was revoked — the
     * caller MUST log the user out and start a new authorization flow.
     *
     * @throws InvalidGrant
     */
    public function refresh(RaccountAccount $account): TokenPair
    {
        if ($account->refresh_token === null) {
            throw new InvalidGrant('No refresh token is stored for this account.');
        }

        try {
            $tokens = $this->client->refresh($account->refresh_token);
        } catch (InvalidGrant $exception) {
            $this->clearTokens($account);

            throw $exception;
        }

        $account->forceFill([
            'access_token' => $tokens->accessToken,
            'refresh_token' => $tokens->refreshToken,
            'access_expires_at' => $tokens->expiresAt(),
        ])->save();

        return $tokens;
    }

    /**
     * Best-effort revocation of both tokens; local secrets are dropped
     * regardless of the server outcome.
     */
    public function revokeAll(RaccountAccount $account): void
    {
        try {
            if ($account->refresh_token !== null) {
                $this->client->revoke($account->refresh_token, 'refresh_token');
            }

            if ($account->access_token !== null) {
                $this->client->revoke($account->access_token, 'access_token');
            }
        } catch (RaccountException $exception) {
            Log::warning('raccount-sso: token revocation failed.', ['exception' => (string) $exception]);
        }

        $this->clearTokens($account);
    }

    private function clearTokens(RaccountAccount $account): void
    {
        $account->forceFill([
            'access_token' => null,
            'refresh_token' => null,
            'access_expires_at' => null,
        ])->save();
    }
}
```

Update `src/RaccountSsoServiceProvider.php` `register()` — append:

```php
        $this->app->singleton(\Raccount\Sso\Tokens\TokenService::class);
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest`
Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add src/Tokens src/RaccountSsoServiceProvider.php tests/Feature/TokenServiceTest.php
git commit -m "feat: token service with encrypted persistence, rotation, and revocation"
```

---

### Task 9: Flow controllers and routes

**Files:**
- Create: `src/Http/Controllers/RedirectController.php`, `src/Http/Controllers/CallbackController.php`, `src/Http/Controllers/LogoutController.php`
- Modify: `src/RaccountSsoServiceProvider.php` (route registration)
- Test: `tests/Feature/FlowTest.php`

**Interfaces:**
- Consumes: `RaccountClient::authorizationUrl/exchangeCode/userinfo` (Tasks 4–5), `UserResolver` (Task 7), `TokenService` (Task 8), `Pkce` (Task 3).
- Produces:
  - `GET {prefix}/redirect` → name `raccount.login` — stores `raccount-sso.state` (64 hex chars) and `raccount-sso.verifier` in the session, redirects to the authorization URL.
  - `GET {prefix}/callback` → name `raccount.callback` — OAuth `error` param → flash `raccount-sso.error` + redirect to route `redirects.on_error`; state validated with `hash_equals` and consumed via `session()->pull()` (mismatch → `abort(419)`); exchanges code, fetches userinfo, resolves the user, `TokenService::storeFor`, `Auth::login()`, `session()->regenerate()`, `redirect()->intended(after_login)`.
  - `GET {prefix}/logout` → name `raccount.logout` (only when `routes.logout_enabled`) — `revokeAll` when a linked account exists, `Auth::logout()`, `session()->invalidate()` + `regenerateToken()`, redirect to `redirects.after_logout`.
  - Session keys: `raccount-sso.state`, `raccount-sso.verifier` — Tasks 10–13 MUST NOT reuse these names.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/FlowTest.php`:

```php
<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Raccount\Sso\Models\RaccountAccount;

it('redirects to the authorization url with state and pkce in the session', function (): void {
    $response = $this->get('/raccount/redirect');

    $target = $response->headers->get('Location');
    parse_str((string) parse_url((string) $target, PHP_URL_QUERY), $query);

    expect($target)->toStartWith('https://account.test/oauth/authorize')
        ->and($query['state'])->toBe(session('raccount-sso.state'))
        ->and(strlen((string) $query['state']))->toBe(64)
        ->and($query['code_challenge'])->toBe(
            rtrim(strtr(base64_encode(hash('sha256', (string) session('raccount-sso.verifier'), true)), '+/', '-_'), '='),
        )
        ->and($query['code_challenge_method'])->toBe('S256');
});

it('completes the callback flow: login, session regeneration, encrypted tokens', function (): void {
    $this->get('/raccount/redirect');
    $state = session('raccount-sso.state');

    Http::fake([
        'account.test/oauth/token' => Http::response([
            'token_type' => 'Bearer',
            'expires_in' => 900,
            'access_token' => 'access-token-value',
            'refresh_token' => 'refresh-token-value',
            'scope' => 'profile email',
        ]),
        'account.test/api/v1/userinfo' => Http::response([
            'sub' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
            'name' => 'Budi Santoso',
            'email' => 'budi@example.com',
            'email_verified' => true,
        ]),
    ]);

    $this->get('/raccount/callback?code=auth-code&state='.$state)
        ->assertRedirect('/home');

    expect(Auth::check())->toBeTrue()
        ->and(Auth::user()->email)->toBe('budi@example.com');

    $account = RaccountAccount::query()->where('raccount_sub', '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0')->first();

    expect($account)->not->toBeNull()
        ->and($account->access_token)->toBe('access-token-value')
        ->and(DB::table('raccount_accounts')->where('id', $account->id)->value('access_token'))
            ->not->toBe('access-token-value');
});

it('aborts with 419 when the state does not match', function (): void {
    session([
        'raccount-sso.state' => str_repeat('a', 64),
        'raccount-sso.verifier' => str_repeat('v', 43),
    ]);

    $this->get('/raccount/callback?code=x&state=wrong')->assertStatus(419);
});

it('aborts with 419 when the state is missing from the session', function (): void {
    $this->get('/raccount/callback?code=x&state=whatever')->assertStatus(419);
});

it('redirects to the error route when the server reports an oauth error', function (): void {
    Illuminate\Support\Facades\Route::get('/login')->name('login');

    $this->get('/raccount/callback?error=access_denied&error_description=User+declined')
        ->assertRedirect('/login')
        ->assertSessionHas('raccount-sso.error');
});

it('logs out, revokes, and destroys the session', function (): void {
    config()->set('raccount-sso.redirects.after_logout', '/bye');

    $user = Raccount\Sso\Tests\Fixtures\User::create(['name' => 'Budi', 'email' => 'budi@example.com']);
    RaccountAccount::query()->create([
        'raccount_sub' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
        'user_type' => RaccountAccount::morphTypeFor($user),
        'user_id' => $user->id,
        'status' => RaccountAccount::STATUS_ACTIVE,
        'access_token' => 'at',
        'refresh_token' => 'rt',
    ]);

    Http::fake(['account.test/oauth/revoke' => Http::response()]);

    $this->actingAs($user)->get('/raccount/logout')->assertRedirect('/bye');

    expect(Auth::check())->toBeFalse();

    $account = RaccountAccount::query()->where('raccount_sub', '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0')->first();

    expect($account->refresh_token)->toBeNull();

    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/oauth/revoke'));
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/FlowTest.php`
Expected: FAIL — routes `raccount.*` not registered (404s).

- [ ] **Step 3: Implement**

`src/Http/Controllers/RedirectController.php`:

```php
<?php

namespace Raccount\Sso\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Raccount\Sso\Client\RaccountClient;
use Raccount\Sso\Flow\Pkce;

final class RedirectController
{
    public function __construct(
        private readonly RaccountClient $client,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $state = bin2hex(random_bytes(32));
        $verifier = Pkce::verifier();

        $request->session()->put('raccount-sso.state', $state);
        $request->session()->put('raccount-sso.verifier', $verifier);

        return redirect()->away(
            $this->client->authorizationUrl($state, Pkce::challenge($verifier), config('raccount-sso.prompt')),
        );
    }
}
```

`src/Http/Controllers/CallbackController.php`:

```php
<?php

namespace Raccount\Sso\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Raccount\Sso\Client\RaccountClient;
use Raccount\Sso\Contracts\UserResolver;
use Raccount\Sso\Tokens\TokenService;

final class CallbackController
{
    public function __construct(
        private readonly RaccountClient $client,
        private readonly TokenService $tokens,
    ) {}

    public function __invoke(Request $request, UserResolver $resolver): RedirectResponse
    {
        if ($request->filled('error')) {
            $request->session()->flash(
                'raccount-sso.error',
                (string) ($request->query('error_description') ?? $request->query('error')),
            );

            return redirect()->route((string) config('raccount-sso.redirects.on_error', 'login'));
        }

        $state = $request->session()->pull('raccount-sso.state');
        $verifier = $request->session()->pull('raccount-sso.verifier');

        if (! is_string($state) || ! is_string($verifier)
            || ! hash_equals($state, (string) $request->query('state', ''))) {
            abort(419, 'The SSO state parameter is invalid or has expired.');
        }

        $tokens = $this->client->exchangeCode((string) $request->query('code', ''), $verifier);
        $userinfo = $this->client->userinfo($tokens->accessToken);
        $user = $resolver->resolve($userinfo);

        $this->tokens->storeFor($user, $userinfo, $tokens);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended((string) config('raccount-sso.redirects.after_login', '/home'));
    }
}
```

`src/Http/Controllers/LogoutController.php`:

```php
<?php

namespace Raccount\Sso\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Raccount\Sso\Models\RaccountAccount;
use Raccount\Sso\Tokens\TokenService;

final class LogoutController
{
    public function __construct(
        private readonly TokenService $tokens,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user !== null) {
            $account = RaccountAccount::query()->forUser($user)->first();

            if ($account !== null) {
                $this->tokens->revokeAll($account);
            }
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->to((string) config('raccount-sso.redirects.after_logout', '/'));
    }
}
```

Update `src/RaccountSsoServiceProvider.php` — add to `boot()`:

```php
        $this->registerRoutes();
```

and add the private method:

```php
    private function registerRoutes(): void
    {
        if (config('raccount-sso.routes.enabled') !== true) {
            return;
        }

        \Illuminate\Support\Facades\Route::middleware((array) config('raccount-sso.routes.middleware', ['web']))
            ->prefix((string) config('raccount-sso.routes.prefix', 'raccount'))
            ->group(static function (): void {
                \Illuminate\Support\Facades\Route::get('/redirect', \Raccount\Sso\Http\Controllers\RedirectController::class)->name('raccount.login');
                \Illuminate\Support\Facades\Route::get('/callback', \Raccount\Sso\Http\Controllers\CallbackController::class)->name('raccount.callback');

                if (config('raccount-sso.routes.logout_enabled') === true) {
                    \Illuminate\Support\Facades\Route::get('/logout', \Raccount\Sso\Http\Controllers\LogoutController::class)->name('raccount.logout');
                }
            });
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest`
Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add src/Http src/RaccountSsoServiceProvider.php tests/Feature/FlowTest.php
git commit -m "feat: sso redirect, callback, and logout flow with routes"
```

---

### Task 10: Middleware (`raccount.active`, `raccount.exclusive`)

**Files:**
- Create: `src/Http/Middleware/EnsureRaccountAccountActive.php`, `src/Http/Middleware/RedirectAuthRoutesToSso.php`
- Modify: `src/RaccountSsoServiceProvider.php` (register aliases)
- Test: `tests/Feature/MiddlewareTest.php`

**Interfaces:**
- Consumes: `RaccountAccount::forUser` (Task 6).
- Produces: middleware aliases `raccount.active` and `raccount.exclusive`:
  - `EnsureRaccountAccountActive`: guest → pass; authenticated user without an `active` link (and `middleware.enforce_status` enabled, default `false`) → `Auth::logout()`, session invalidate + regenerate token, flash `raccount-sso.error`, redirect to route `redirects.on_error`. When `enforce_status` is `false` the middleware is a no-op passthrough.
  - `RedirectAuthRoutesToSso`: unauthenticated request whose route name is in `exclusive.routes` → redirect to route `raccount.login`; everything else passes.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/MiddlewareTest.php`:

```php
<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Raccount\Sso\Models\RaccountAccount;
use Raccount\Sso\Tests\Fixtures\User;

beforeEach(function (): void {
    Route::get('/login', fn () => 'login')->name('login');
    Route::get('/protected', fn () => 'ok')->middleware('raccount.active')->name('protected');
});

it('lets guests through', function (): void {
    $this->get('/protected')->assertOk();
});

it('lets authenticated users with an active account through', function (): void {
    config()->set('raccount-sso.middleware.enforce_status', true);

    $user = User::create(['name' => 'Budi', 'email' => 'budi@example.com']);
    RaccountAccount::query()->create([
        'raccount_sub' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
        'user_type' => RaccountAccount::morphTypeFor($user),
        'user_id' => $user->id,
        'status' => RaccountAccount::STATUS_ACTIVE,
    ]);

    $this->actingAs($user)->get('/protected')->assertOk();
});

it('logs out users whose raccount account is not active', function (): void {
    config()->set('raccount-sso.middleware.enforce_status', true);

    $user = User::create(['name' => 'Budi', 'email' => 'budi@example.com']);
    RaccountAccount::query()->create([
        'raccount_sub' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
        'user_type' => RaccountAccount::morphTypeFor($user),
        'user_id' => $user->id,
        'status' => RaccountAccount::STATUS_SUSPENDED,
    ]);

    $this->actingAs($user)->get('/protected')->assertRedirect('/login');
});

it('does not enforce status when disabled', function (): void {
    $user = User::create(['name' => 'Budi', 'email' => 'budi@example.com']);
    RaccountAccount::query()->create([
        'raccount_sub' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
        'user_type' => RaccountAccount::morphTypeFor($user),
        'user_id' => $user->id,
        'status' => RaccountAccount::STATUS_SUSPENDED,
    ]);

    $this->actingAs($user)->get('/protected')->assertOk();
});

it('redirects guests away from local auth routes in exclusive mode', function (): void {
    Route::get('/register', fn () => 'register')->name('register');
    Route::middleware('raccount.exclusive')->group(static function (): void {
        Route::get('/login', fn () => 'login')->name('login');
        Route::get('/register', fn () => 'register')->name('register');
    });

    $this->get('/login')->assertRedirect(route('raccount.login'));
    $this->get('/register')->assertRedirect(route('raccount.login'));
});

it('leaves authenticated users on local auth routes in exclusive mode', function (): void {
    Route::middleware('raccount.exclusive')->group(static function (): void {
        Route::get('/login', fn () => 'login')->name('login');
    });

    $user = User::create(['name' => 'Budi', 'email' => 'budi@example.com']);

    $this->actingAs($user)->get('/login')->assertOk();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/MiddlewareTest.php`
Expected: FAIL — alias not registered (exception "middleware [raccount.active] not found").

- [ ] **Step 3: Implement**

`src/Http/Middleware/EnsureRaccountAccountActive.php`:

```php
<?php

namespace Raccount\Sso\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Raccount\Sso\Models\RaccountAccount;
use Symfony\Component\HttpFoundation\Response;

class EnsureRaccountAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || config('raccount-sso.middleware.enforce_status') !== true) {
            return $next($request);
        }

        $active = RaccountAccount::query()
            ->forUser($user)
            ->where('status', RaccountAccount::STATUS_ACTIVE)
            ->exists();

        if ($active) {
            return $next($request);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->flash(
            'raccount-sso.error',
            'Your RAccount identity is suspended or deleted on this application.',
        );

        return new RedirectResponse(
            route((string) config('raccount-sso.redirects.on_error', 'login')),
        );
    }
}
```

`src/Http/Middleware/RedirectAuthRoutesToSso.php`:

```php
<?php

namespace Raccount\Sso\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RedirectAuthRoutesToSso
{
    public function handle(Request $request, Closure $next): Response
    {
        $takenOver = (array) config('raccount-sso.exclusive.routes', []);

        if ($takenOver === [] || $request->user() !== null || $request->route() === null) {
            return $next($request);
        }

        if (in_array($request->route()->getName(), $takenOver, true)) {
            return redirect()->route('raccount.login');
        }

        return $next($request);
    }
}
```

Update `src/RaccountSsoServiceProvider.php` `boot()` — add:

```php
        $this->app->make(\Illuminate\Routing\Router::class)->aliasMiddleware(
            'raccount.active',
            \Raccount\Sso\Http\Middleware\EnsureRaccountAccountActive::class,
        );
        $this->app->make(\Illuminate\Routing\Router::class)->aliasMiddleware(
            'raccount.exclusive',
            \Raccount\Sso\Http\Middleware\RedirectAuthRoutesToSso::class,
        );
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest`
Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add src/Http/Middleware src/RaccountSsoServiceProvider.php tests/Feature/MiddlewareTest.php
git commit -m "feat: account status and exclusive mode middleware"
```

---

### Task 11: Webhooks (verifier, controller, events, listener)

**Files:**
- Create: `src/Webhooks/WebhookVerifier.php`, `src/Webhooks/WebhookPayload.php`, `src/Webhooks/WebhookController.php`
- Create: `src/Events/UserEvent.php`, `src/Events/UserCreated.php`, `src/Events/UserUpdated.php`, `src/Events/UserSuspended.php`, `src/Events/UserReactivated.php`, `src/Events/UserDeleted.php`, `src/Events/WebhookReceived.php`
- Create: `src/Listeners/UpdateAccountStatus.php`
- Modify: `src/RaccountSsoServiceProvider.php` (webhook route + listeners)
- Test: `tests/Unit/WebhookVerifierTest.php`, `tests/Feature/WebhookTest.php`

**Interfaces:**
- Consumes: `RaccountWebhookEvent` (Task 6), config (Task 2).
- Produces:
  - `WebhookVerifier::__construct(array $secrets, int $tolerance = 300)`; `verify(string $rawBody, ?string $signatureHeader, ?string $timestampHeader): bool` — constant-time HMAC over every configured secret, `sha256=<64 lowercase hex>` format, ISO-8601 timestamp within ±tolerance.
  - `WebhookPayload`: readonly `eventId`, `type`, `?CarbonImmutable $occurredAt`, `array $actor`, `array $data`, `array $changed`; `sub(): ?string`; static `fromArray(array $body, string $eventId, string $type): self`; static `eventClassFor(string $type): ?string` mapping `user.created|user.updated|user.suspended|user.reactivated|user.deleted` to event classes.
  - Events: abstract `UserEvent` (public readonly `WebhookPayload $payload`), five finals, plus `WebhookReceived` (same constructor shape).
  - `UpdateAccountStatus::handle(UserEvent $event): void` — updates link status/snapshot when a link exists; never creates rows.
  - Route `POST {webhooks.path}` (default `raccount/webhook`), registered WITHOUT the `web` group (no CSRF), with `webhooks.middleware` (default `throttle:60,1`).
  - Behaviour: verification failure → `200` empty + `Log::warning` (permanent, stop retries); duplicate `event_id` with `processed_at` set → `200`; claim row created with `processed_at = null`, events dispatched, then `processed_at = now()`; listener exception → `report()` + `500` empty (server retries).

- [ ] **Step 1: Write the failing unit tests**

`tests/Unit/WebhookVerifierTest.php`:

```php
<?php

use Raccount\Sso\Webhooks\WebhookVerifier;

it('accepts a correctly signed delivery within the window', function (): void {
    $body = '{"type":"user.updated"}';
    $verifier = new WebhookVerifier(['whsec_one'], 300);

    $signature = 'sha256='.hash_hmac('sha256', $body, 'whsec_one');
    $timestamp = now()->toIso8601ZuluString();

    expect($verifier->verify($body, $signature, $timestamp))->toBeTrue();
});

it('accepts any configured secret (rotation)', function (): void {
    $body = '{"type":"user.updated"}';
    $verifier = new WebhookVerifier(['whsec_old', 'whsec_new'], 300);

    $signature = 'sha256='.hash_hmac('sha256', $body, 'whsec_new');

    expect($verifier->verify($body, $signature, now()->toIso8601ZuluString()))->toBeTrue();
});

it('rejects a bad signature', function (): void {
    $verifier = new WebhookVerifier(['whsec_one'], 300);

    expect($verifier->verify('body', 'sha256='.str_repeat('0', 64), now()->toIso8601ZuluString()))->toBeFalse();
});

it('rejects malformed signature headers', function (): void {
    $verifier = new WebhookVerifier(['whsec_one'], 300);

    expect($verifier->verify('body', null, now()->toIso8601ZuluString()))->toBeFalse()
        ->and($verifier->verify('body', 'sha256=not-hex', now()->toIso8601ZuluString()))->toBeFalse()
        ->and($verifier->verify('body', 'md5='.str_repeat('0', 64), now()->toIso8601ZuluString()))->toBeFalse();
});

it('rejects stale timestamps', function (): void {
    $body = 'body';
    $verifier = new WebhookVerifier(['whsec_one'], 300);
    $signature = 'sha256='.hash_hmac('sha256', $body, 'whsec_one');
    $stale = now()->subMinutes(10)->toIso8601ZuluString();

    expect($verifier->verify($body, $signature, $stale))->toBeFalse();
});

it('rejects unparseable timestamps', function (): void {
    $body = 'body';
    $verifier = new WebhookVerifier(['whsec_one'], 300);
    $signature = 'sha256='.hash_hmac('sha256', $body, 'whsec_one');

    expect($verifier->verify($body, $signature, 'not-a-date'))->toBeFalse()
        ->and($verifier->verify($body, $signature, null))->toBeFalse();
});
```

- [ ] **Step 2: Write the failing feature tests**

`tests/Feature/WebhookTest.php`:

```php
<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Raccount\Sso\Events\UserSuspended;
use Raccount\Sso\Events\UserUpdated;
use Raccount\Sso\Events\WebhookReceived;
use Raccount\Sso\Models\RaccountAccount;
use Raccount\Sso\Tests\Fixtures\User;

function postWebhook(array $body, array $headers = [], ?string $secret = 'whsec_test', ?string $timestamp = null): \Illuminate\Testing\TestResponse
{
    config()->set('raccount-sso.webhooks.enabled', true);
    config()->set('raccount-sso.webhooks.secrets', $secret === null ? [] : [$secret]);

    $raw = json_encode($body, JSON_THROW_ON_ERROR);
    $timestamp ??= CarbonImmutable::now('UTC')->toIso8601ZuluString();

    $server = test()->transformHeadersToServerVars(array_merge([
        'X-RAccount-Event-Id' => (string) ($body['id'] ?? '01JABCDEFGHJKMNPQRSTVWXYZ'),
        'X-RAccount-Event-Type' => (string) ($body['type'] ?? 'user.updated'),
        'X-RAccount-Timestamp' => $timestamp,
        'X-RAccount-Signature' => 'sha256='.hash_hmac('sha256', $raw, $secret ?? ''),
    ], $headers));

    return test()->call('POST', 'raccount/webhook', [], [], [], $server, $raw);
}

it('accepts a signed webhook, records it, and dispatches events', function (): void {
    Event::fake([WebhookReceived::class, UserUpdated::class]);

    postWebhook([
        'id' => '01JABCDEFGHJKMNPQRSTVWXYZ',
        'type' => 'user.updated',
        'occurred_at' => CarbonImmutable::now('UTC')->toIso8601ZuluString(),
        'actor' => ['type' => 'user', 'id' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0'],
        'data' => ['sub' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0', 'name' => 'New Name'],
        'changed' => ['name'],
    ])->assertOk();

    Event::assertDispatched(WebhookReceived::class);
    Event::assertDispatched(UserUpdated::class, fn ($event): bool => $event->payload->sub() === '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0');

    expect(\Raccount\Sso\Models\RaccountWebhookEvent::query()->find('01JABCDEFGHJKMNPQRSTVWXYZ')->processed_at)->not->toBeNull();
});

it('returns 200 without dispatching when the signature is invalid', function (): void {
    Event::fake([WebhookReceived::class]);

    postWebhook(['id' => '01JABCDEFGHJKMNPQRSTVWXYZ', 'type' => 'user.updated', 'data' => []], ['X-RAccount-Signature' => 'sha256='.str_repeat('0', 64)])
        ->assertOk();

    Event::assertNotDispatched(WebhookReceived::class);
});

it('ignores a replayed event id that was already processed', function (): void {
    Event::fake([WebhookReceived::class]);

    postWebhook(['id' => '01JABCDEFGHJKMNPQRSTVWXYZ', 'type' => 'user.updated', 'data' => []])->assertOk();
    postWebhook(['id' => '01JABCDEFGHJKMNPQRSTVWXYZ', 'type' => 'user.updated', 'data' => []])->assertOk();

    Event::assertDispatchedTimes(WebhookReceived::class, 1);
});

it('updates the linked account status from the built-in listener', function (): void {
    $user = User::create(['name' => 'Budi', 'email' => 'budi@example.com']);
    RaccountAccount::query()->create([
        'raccount_sub' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
        'user_type' => RaccountAccount::morphTypeFor($user),
        'user_id' => $user->id,
        'status' => RaccountAccount::STATUS_ACTIVE,
        'name' => 'Budi',
    ]);

    postWebhook([
        'id' => '01JZZZZZZZZZZZZZZZZZZZZZZZZ',
        'type' => 'user.suspended',
        'occurred_at' => CarbonImmutable::now('UTC')->toIso8601ZuluString(),
        'actor' => ['type' => 'admin', 'id' => 'x'],
        'data' => ['sub' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0', 'name' => 'Budi'],
    ])->assertOk();

    expect($user->fresh()->name)->toBe('Budi')
        ->and(RaccountAccount::query()->where('raccount_sub', '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0')->value('status'))
            ->toBe(RaccountAccount::STATUS_SUSPENDED);
});

it('returns 500 when a listener throws so the server retries', function (): void {
    Event::listen(UserUpdated::class, static function (): void {
        throw new RuntimeException('listener exploded');
    });

    postWebhook(['id' => '01JAAAAAAAAAAAAAAAAAAAAAAAA', 'type' => 'user.updated', 'data' => []])->assertStatus(500);

    expect(\Raccount\Sso\Models\RaccountWebhookEvent::query()->find('01JAAAAAAAAAAAAAAAAAAAAAAAA')->processed_at)->toBeNull();
});
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Unit/WebhookVerifierTest.php tests/Feature/WebhookTest.php`
Expected: FAIL — classes missing.

- [ ] **Step 4: Implement**

`src/Webhooks/WebhookVerifier.php`:

```php
<?php

namespace Raccount\Sso\Webhooks;

use Carbon\CarbonImmutable;
use Throwable;

final class WebhookVerifier
{
    /**
     * @param  list<non-empty-string>  $secrets
     */
    public function __construct(
        private readonly array $secrets,
        private readonly int $tolerance = 300,
    ) {}

    public function verify(string $rawBody, ?string $signatureHeader, ?string $timestampHeader): bool
    {
        if ($signatureHeader === null || ! str_starts_with($signatureHeader, 'sha256=')) {
            return false;
        }

        $provided = strtolower(substr($signatureHeader, 7));

        if (preg_match('/^[0-9a-f]{64}$/', $provided) !== 1) {
            return false;
        }

        foreach ($this->secrets as $secret) {
            $expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);

            if (hash_equals($expected, $signatureHeader)) {
                return $this->timestampWithinWindow($timestampHeader);
            }
        }

        return false;
    }

    private function timestampWithinWindow(?string $timestampHeader): bool
    {
        if ($timestampHeader === null) {
            return false;
        }

        try {
            $timestamp = CarbonImmutable::parse($timestampHeader);
        } catch (Throwable) {
            return false;
        }

        return abs(CarbonImmutable::now('UTC')->diffInSeconds($timestamp)) <= $this->tolerance;
    }
}
```

`src/Webhooks/WebhookPayload.php`:

```php
<?php

namespace Raccount\Sso\Webhooks;

use Carbon\CarbonImmutable;
use Raccount\Sso\Events\UserCreated;
use Raccount\Sso\Events\UserDeleted;
use Raccount\Sso\Events\UserReactivated;
use Raccount\Sso\Events\UserSuspended;
use Raccount\Sso\Events\UserUpdated;

final class WebhookPayload
{
    /**
     * @param  array{type?: string, id?: string}  $actor
     * @param  array<string, mixed>  $data
     * @param  list<string>  $changed
     */
    public function __construct(
        public readonly string $eventId,
        public readonly string $type,
        public readonly ?CarbonImmutable $occurredAt,
        public readonly array $actor,
        public readonly array $data,
        public readonly array $changed,
    ) {}

    /**
     * @param  array<string, mixed>  $body
     */
    public static function fromArray(array $body, string $eventId, string $type): self
    {
        return new self(
            eventId: $eventId,
            type: $type,
            occurredAt: isset($body['occurred_at']) && $body['occurred_at'] !== null
                ? CarbonImmutable::parse((string) $body['occurred_at'])
                : null,
            actor: (array) ($body['actor'] ?? []),
            data: (array) ($body['data'] ?? []),
            changed: array_values((array) ($body['changed'] ?? [])),
        );
    }

    /**
     * @return class-string<\Raccount\Sso\Events\UserEvent>|null
     */
    public static function eventClassFor(string $type): ?string
    {
        return match ($type) {
            'user.created' => UserCreated::class,
            'user.updated' => UserUpdated::class,
            'user.suspended' => UserSuspended::class,
            'user.reactivated' => UserReactivated::class,
            'user.deleted' => UserDeleted::class,
            default => null,
        };
    }

    public function sub(): ?string
    {
        $sub = $this->data['sub'] ?? null;

        return is_string($sub) ? $sub : null;
    }
}
```

`src/Events/UserEvent.php`:

```php
<?php

namespace Raccount\Sso\Events;

use Raccount\Sso\Webhooks\WebhookPayload;

abstract class UserEvent
{
    public function __construct(
        public readonly WebhookPayload $payload,
    ) {}
}
```

`src/Events/UserCreated.php` (and the four siblings with the same body — create `UserUpdated.php`, `UserSuspended.php`, `UserReactivated.php`, `UserDeleted.php` changing only the class name):

```php
<?php

namespace Raccount\Sso\Events;

final class UserCreated extends UserEvent
{
}
```

`src/Events/WebhookReceived.php`:

```php
<?php

namespace Raccount\Sso\Events;

use Raccount\Sso\Webhooks\WebhookPayload;

final class WebhookReceived
{
    public function __construct(
        public readonly WebhookPayload $payload,
    ) {}
}
```

`src/Listeners/UpdateAccountStatus.php`:

```php
<?php

namespace Raccount\Sso\Listeners;

use Raccount\Sso\Events\UserCreated;
use Raccount\Sso\Events\UserDeleted;
use Raccount\Sso\Events\UserEvent;
use Raccount\Sso\Events\UserReactivated;
use Raccount\Sso\Events\UserSuspended;
use Raccount\Sso\Events\UserUpdated;
use Raccount\Sso\Models\RaccountAccount;

class UpdateAccountStatus
{
    /** @var array<class-string<UserEvent>, string> */
    private const STATUS_BY_EVENT = [
        UserCreated::class => RaccountAccount::STATUS_ACTIVE,
        UserUpdated::class => RaccountAccount::STATUS_ACTIVE,
        UserReactivated::class => RaccountAccount::STATUS_ACTIVE,
        UserSuspended::class => RaccountAccount::STATUS_SUSPENDED,
        UserDeleted::class => RaccountAccount::STATUS_DELETED,
    ];

    public function handle(UserEvent $event): void
    {
        $sub = $event->payload->sub();

        if ($sub === null) {
            return;
        }

        $account = RaccountAccount::query()->where('raccount_sub', $sub)->first();

        if ($account === null) {
            return;
        }

        $account->forceFill(array_merge(
            ['status' => self::STATUS_BY_EVENT[$event::class]],
            $this->snapshot($event->payload->data),
        ))->save();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function snapshot(array $data): array
    {
        $changes = [];

        if (array_key_exists('name', $data)) {
            $changes['name'] = $data['name'];
        }
        if (array_key_exists('email', $data)) {
            $changes['email'] = $data['email'];
        }
        if (array_key_exists('picture', $data)) {
            $changes['picture_url'] = $data['picture'];
        }

        return $changes;
    }
}
```

`src/Webhooks/WebhookController.php`:

```php
<?php

namespace Raccount\Sso\Webhooks;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Raccount\Sso\Events\WebhookReceived;
use Raccount\Sso\Models\RaccountWebhookEvent;
use Throwable;

final class WebhookController
{
    public function __construct(
        private readonly Dispatcher $events,
    ) {}

    public function __invoke(Request $request): Response
    {
        $rawBody = $request->getContent();
        $eventId = (string) $request->header('X-RAccount-Event-Id', '');

        $secrets = array_values(array_filter((array) config('raccount-sso.webhooks.secrets', [])));
        $verifier = new WebhookVerifier($secrets, (int) config('raccount-sso.webhooks.tolerance', 300));

        if ($eventId === '' || ! $verifier->verify(
            $rawBody,
            $request->header('X-RAccount-Signature'),
            $request->header('X-RAccount-Timestamp'),
        )) {
            // Permanent failure — acknowledge so the server stops retrying,
            // and record enough context to debug from the application log.
            Log::warning('raccount-sso: webhook delivery rejected (signature/timestamp/event id).', [
                'event_id' => $eventId,
            ]);

            return new Response('', 200);
        }

        $claim = RaccountWebhookEvent::query()->find($eventId);

        if ($claim !== null && $claim->processed_at !== null) {
            return new Response('', 200);
        }

        if ($claim === null) {
            try {
                $claim = RaccountWebhookEvent::query()->create([
                    'event_id' => $eventId,
                    'event_type' => (string) $request->header('X-RAccount-Event-Type', ''),
                    'payload' => json_decode($rawBody, true),
                    'received_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                $claim = RaccountWebhookEvent::query()->findOrFail($eventId);
            }
        }

        $type = (string) $request->header('X-RAccount-Event-Type', '');
        $payload = WebhookPayload::fromArray((array) json_decode($rawBody, true), $eventId, $type);

        try {
            $this->events->dispatch(new WebhookReceived($payload));

            $eventClass = WebhookPayload::eventClassFor($type);

            if ($eventClass !== null) {
                $this->events->dispatch(new $eventClass($payload));
            }

            $claim->forceFill(['processed_at' => now()])->save();
        } catch (Throwable $exception) {
            // Let the server retry this delivery via its retry ladder.
            report($exception);

            return new Response('', 500);
        }

        return new Response('', 200);
    }
}
```

Update `src/RaccountSsoServiceProvider.php` — in `boot()`, inside `registerRoutes()` append after the web-group block:

```php
        if (config('raccount-sso.webhooks.enabled') === true) {
            \Illuminate\Support\Facades\Route::post(
                (string) config('raccount-sso.webhooks.path', 'raccount/webhook'),
                \Raccount\Sso\Webhooks\WebhookController::class,
            )->middleware((array) config('raccount-sso.webhooks.middleware', ['throttle:60,1']));
        }
```

and in `boot()` add listener registration:

```php
        if (config('raccount-sso.webhooks.enabled') === true && config('raccount-sso.webhooks.listeners_enabled') === true) {
            foreach ([\Raccount\Sso\Events\UserCreated::class, \Raccount\Sso\Events\UserUpdated::class, \Raccount\Sso\Events\UserSuspended::class, \Raccount\Sso\Events\UserReactivated::class, \Raccount\Sso\Events\UserDeleted::class] as $webhookEvent) {
                \Illuminate\Support\Facades\Event::listen($webhookEvent, \Raccount\Sso\Listeners\UpdateAccountStatus::class);
            }
        }
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/pest`
Expected: all tests pass. If the `report()` helper is unavailable in the test exception test, assert only on the 500 + unprocessed row.

- [ ] **Step 6: Commit**

```bash
git add src/Webhooks src/Events src/Listeners src/RaccountSsoServiceProvider.php tests/Unit/WebhookVerifierTest.php tests/Feature/WebhookTest.php
git commit -m "feat: signed webhook receiver with replay protection and status listener"
```

---

### Task 12: Directory sync (M2M)

**Files:**
- Create: `src/Directory/ClientCredentialsManager.php`, `src/Directory/DirectorySyncService.php`, `src/Events/DirectoryUserRetrieved.php`
- Modify: `src/RaccountSsoServiceProvider.php` (singletons)
- Test: `tests/Feature/DirectoryTest.php`

**Interfaces:**
- Consumes: `RaccountClient::clientCredentialsToken/directoryPage` (Task 5).
- Produces:
  - `ClientCredentialsManager::token(): string` — cached (store from `directory.cache_store`, default app default) at key `raccount-sso:m2m-token`, TTL = expiry − 60s (minimum 60s); `flush(): void`.
  - `DirectorySyncService::users(?DateTimeInterface $updatedSince = null): LazyCollection<int, DirectoryUser>` — walks cursor pages until `next_cursor === null`, dispatching `DirectoryUserRetrieved` for each record.
  - `DirectoryUserRetrieved` event with public readonly `DirectoryUser $user`.
  - Config `directory.scope` (default `sync:read`) drives the requested scope.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/DirectoryTest.php`:

```php
<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Raccount\Sso\Directory\ClientCredentialsManager;
use Raccount\Sso\Directory\DirectorySyncService;
use Raccount\Sso\Events\DirectoryUserRetrieved;

function directoryRecord(string $sub, string $name): array
{
    return [
        'sub' => $sub,
        'name' => $name,
        'email' => strtolower(str_replace(' ', '.', $name)).'@example.com',
        'email_verified' => true,
        'status' => 'active',
        'updated_at' => '2026-09-07T00:00:00Z',
    ];
}

it('walks directory pages until the cursor is exhausted', function (): void {
    config()->set('raccount-sso.directory.enabled', true);

    Http::fake([
        'account.test/oauth/token' => Http::response([
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'access_token' => 'm2m-token',
            'scope' => 'sync:read',
        ]),
        'account.test/api/v1/directory/users*' => Http::sequence()
            ->push(['data' => [directoryRecord('sub-1', 'Budi One')], 'next_cursor' => 'CUR1'])
            ->push(['data' => [directoryRecord('sub-2', 'Budi Two'), directoryRecord('sub-3', 'Budi Three')], 'next_cursor' => null]),
    ]);

    $users = app(DirectorySyncService::class)->users()->collect();

    expect($users)->toHaveCount(3)
        ->and($users->pluck('sub')->all())->toBe(['sub-1', 'sub-2', 'sub-3']);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'cursor=CUR1'));
});

it('dispatches an event per retrieved user', function (): void {
    config()->set('raccount-sso.directory.enabled', true);
    Event::fake([DirectoryUserRetrieved::class]);

    Http::fake([
        'account.test/oauth/token' => Http::response([
            'expires_in' => 3600,
            'access_token' => 'm2m-token',
        ]),
        'account.test/api/v1/directory/users*' => Http::response([
            'data' => [directoryRecord('sub-1', 'Budi One')],
            'next_cursor' => null,
        ]),
    ]);

    app(DirectorySyncService::class)->users()->collect();

    Event::assertDispatchedTimes(DirectoryUserRetrieved::class, 1);
});

it('caches the machine token between pages', function (): void {
    config()->set('raccount-sso.directory.enabled', true);

    Http::fake([
        'account.test/oauth/token' => Http::response([
            'expires_in' => 3600,
            'access_token' => 'm2m-token',
        ]),
        'account.test/api/v1/directory/users*' => Http::sequence()
            ->push(['data' => [directoryRecord('sub-1', 'Budi One')], 'next_cursor' => 'CUR1'])
            ->push(['data' => [], 'next_cursor' => null]),
    ]);

    app(DirectorySyncService::class)->users()->collect();

    Http::assertSentInOrder([
        fn ($request): bool => str_ends_with($request->url(), '/oauth/token'),
        fn ($request): bool => str_contains($request->url(), 'cursor=CUR1'),
    ]);

    expect(app(ClientCredentialsManager::class)->token())->toBe('m2m-token');
    Http::assertSentCount(3); // token + 2 pages, no second token request
});

it('flushes the cached machine token', function (): void {
    config()->set('raccount-sso.directory.enabled', true);

    Http::fake([
        'account.test/oauth/token' => Http::sequence()
            ->push(['expires_in' => 3600, 'access_token' => 'first'])
            ->push(['expires_in' => 3600, 'access_token' => 'second']),
    ]);

    $manager = app(ClientCredentialsManager::class);

    expect($manager->token())->toBe('first');

    $manager->flush();

    expect($manager->token())->toBe('second');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/DirectoryTest.php`
Expected: FAIL — classes missing.

- [ ] **Step 3: Implement**

`src/Events/DirectoryUserRetrieved.php`:

```php
<?php

namespace Raccount\Sso\Events;

use Raccount\Sso\Client\Dto\DirectoryUser;

final class DirectoryUserRetrieved
{
    public function __construct(
        public readonly DirectoryUser $user,
    ) {}
}
```

`src/Directory/ClientCredentialsManager.php`:

```php
<?php

namespace Raccount\Sso\Directory;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Cache\Factory;
use Raccount\Sso\Client\RaccountClient;

class ClientCredentialsManager
{
    private const CACHE_KEY = 'raccount-sso:m2m-token';

    public function __construct(
        private readonly RaccountClient $client,
        private readonly Factory $cacheFactory,
    ) {}

    public function token(): string
    {
        $cache = $this->cache();

        $cached = $cache->get(self::CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $scope = (string) config('raccount-sso.directory.scope', 'sync:read');
        $tokens = $this->client->clientCredentialsToken($scope);

        $ttl = max(60, $tokens->expiresAt()->getTimestamp() - time() - 60);
        $cache->put(self::CACHE_KEY, $tokens->accessToken, $ttl);

        return $tokens->accessToken;
    }

    public function flush(): void
    {
        $this->cache()->forget(self::CACHE_KEY);
    }

    private function cache(): Repository
    {
        $store = config('raccount-sso.directory.cache_store');

        return $this->cacheFactory->store($store);
    }
}
```

`src/Directory/DirectorySyncService.php`:

```php
<?php

namespace Raccount\Sso\Directory;

use DateTimeInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\LazyCollection;
use Raccount\Sso\Client\Dto\DirectoryUser;
use Raccount\Sso\Client\RaccountClient;
use Raccount\Sso\Events\DirectoryUserRetrieved;

class DirectorySyncService
{
    public function __construct(
        private readonly RaccountClient $client,
        private readonly ClientCredentialsManager $credentials,
        private readonly Dispatcher $events,
    ) {}

    /**
     * Walk the directory (cursor pagination) lazily. Each retrieved record
     * emits DirectoryUserRetrieved so applications can provision users.
     *
     * @return LazyCollection<int, DirectoryUser>
     */
    public function users(?DateTimeInterface $updatedSince = null): LazyCollection
    {
        $limit = (int) config('raccount-sso.directory.page_limit', 200);

        return LazyCollection::make(function () use ($updatedSince, $limit): \Generator {
            $cursor = null;

            do {
                $page = $this->client->directoryPage(
                    $this->credentials->token(),
                    $cursor,
                    $updatedSince,
                    $limit,
                );

                foreach ($page->data as $record) {
                    $this->events->dispatch(new DirectoryUserRetrieved($record));

                    yield $record;
                }

                $cursor = $page->nextCursor;
            } while ($cursor !== null);
        });
    }
}
```

Update `src/RaccountSsoServiceProvider.php` `register()` — append:

```php
        $this->app->singleton(\Raccount\Sso\Directory\ClientCredentialsManager::class);
        $this->app->singleton(\Raccount\Sso\Directory\DirectorySyncService::class);
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest`
Expected: all tests pass. Remove the unused `use Carbon\CarbonImmutable;` import in `DirectorySyncService.php` if Larastan flags it.

- [ ] **Step 5: Commit**

```bash
git add src/Directory src/Events/DirectoryUserRetrieved.php src/RaccountSsoServiceProvider.php tests/Feature/DirectoryTest.php
git commit -m "feat: m2m directory sync with cached client credentials and cursor pagination"
```

---

### Task 13: Artisan commands

**Files:**
- Create: `src/Commands/CheckCommand.php`, `src/Commands/SyncDirectoryCommand.php`, `src/Commands/PruneWebhookEventsCommand.php`
- Modify: `src/RaccountSsoServiceProvider.php` (register commands)
- Test: `tests/Feature/CommandsTest.php`

**Interfaces:**
- Consumes: `RaccountClient::ping` (Task 4), `DirectorySyncService` (Task 12), `RaccountWebhookEvent` (Task 6).
- Produces: commands registered when `runningInConsole()`:
  - `raccount:check` — verifies config completeness (base URL HTTPS, client id/secret), ping connectivity, required tables, webhook secrets when enabled, and prints a ✓/✗ table with remediation hints; exit code `FAILURE` on any failure, `SUCCESS` otherwise.
  - `raccount:directory:sync {--since=}` — runs the directory walk, prints processed count and duration; `FAILURE` on `RaccountException`.
  - `raccount:prune-webhooks {--days=30}` — deletes webhook event rows older than `--days`; prints the deleted count.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/CommandsTest.php`:

```php
<?php

use Illuminate\Support\Facades\Http;
use Raccount\Sso\Models\RaccountWebhookEvent;

it('passes raccount:check when everything is configured and reachable', function (): void {
    Http::fake(['account.test/api/v1/ping' => Http::response(['status' => 'ok'])]);

    $this->artisan('raccount:check')->assertSuccessful();
});

it('fails raccount:check when the server is unreachable', function (): void {
    Http::fake(['account.test/api/v1/ping' => Http::response(['status' => 'error'], 500)]);

    $this->artisan('raccount:check')->assertFailed();
});

it('fails raccount:check when the client secret is missing', function (): void {
    config()->set('raccount-sso.client.secret', null);

    $this->artisan('raccount:check')->assertFailed();
});

it('fails raccount:check when webhooks are enabled without secrets', function (): void {
    config()->set('raccount-sso.webhooks.enabled', true);
    config()->set('raccount-sso.webhooks.secrets', []);

    $this->artisan('raccount:check')->assertFailed();
});

it('syncs the directory', function (): void {
    config()->set('raccount-sso.directory.enabled', true);

    Http::fake([
        'account.test/oauth/token' => Http::response(['expires_in' => 3600, 'access_token' => 'm2m']),
        'account.test/api/v1/directory/users*' => Http::response([
            'data' => [[
                'sub' => 'sub-1',
                'name' => 'Budi',
                'email' => 'budi@example.com',
                'email_verified' => true,
                'status' => 'active',
                'updated_at' => '2026-09-07T00:00:00Z',
            ]],
            'next_cursor' => null,
        ]),
    ]);

    $this->artisan('raccount:directory:sync')->assertSuccessful();
});

it('prunes old webhook events', function (): void {
    RaccountWebhookEvent::query()->create([
        'event_id' => '01JABCDEFGHJKMNPQRSTVWXYZ',
        'event_type' => 'user.updated',
        'payload' => [],
        'received_at' => now()->subDays(45),
    ]);

    $this->artisan('raccount:prune-webhooks', ['--days' => 30])->assertSuccessful();

    expect(RaccountWebhookEvent::query()->count())->toBe(0);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/CommandsTest.php`
Expected: FAIL — commands not found.

- [ ] **Step 3: Implement**

`src/Commands/CheckCommand.php`:

```php
<?php

namespace Raccount\Sso\Commands;

use Illuminate\Console\Command;
use Illuminate\Routing\RouteNotFoundException;
use Illuminate\Support\Facades\Schema;
use Raccount\Sso\Client\RaccountClient;
use Raccount\Sso\Exceptions\RaccountException;
use Throwable;

final class CheckCommand extends Command
{
    protected $signature = 'raccount:check';

    protected $description = 'Verify the RAccount SSO configuration, database, and connectivity';

    public function handle(RaccountClient $client): int
    {
        $failures = 0;

        $check = function (string $label, callable $check, string $hint): use (&$failures): void {
            try {
                $passed = (bool) $check();
            } catch (Throwable) {
                $passed = false;
            }

            if ($passed) {
                $this->components->twoColumnDetail($label, '<fg=green;options=bold>OK</>');
            } else {
                $failures++;
                $this->components->twoColumnDetail($label, '<fg=red;options=bold>FAIL</>');
                $this->components->task('  ↳ '.$hint, static fn (): bool => true);
            }
        };

        $check('server.base_url configured and HTTPS', static fn (): bool => is_string(config('raccount-sso.server.base_url'))
            && str_starts_with((string) config('raccount-sso.server.base_url'), 'https://'),
            'Set RACCOUNT_SSO_SERVER_URL to the HTTPS base URL of the RAccount server.');

        $check('client.id configured', static fn (): bool => filled(config('raccount-sso.client.id')),
            'Set RACCOUNT_SSO_CLIENT_ID (issued by the RAccount admin).');

        $check('client.secret configured', static fn (): bool => filled(config('raccount-sso.client.secret')),
            'Set RACCOUNT_SSO_CLIENT_SECRET (shown once at client creation).');

        $check('client.redirect_uri configured', static fn (): bool => filled(config('raccount-sso.client.redirect_uri')),
            'Set RACCOUNT_SSO_REDIRECT_URI to the exact HTTPS callback registered server-side.');

        try {
            $callback = route('raccount.callback');
        } catch (RouteNotFoundException) {
            $callback = null;
        }

        $check('callback route registered', static fn (): bool => is_string($callback),
            'Enable raccount-sso.routes or register the callback route yourself.');

        $check('raccount_accounts table exists', static fn (): bool => Schema::hasTable('raccount_accounts'),
            'Run `php artisan migrate` after publishing the package migrations.');

        $check('raccount_webhook_events table exists', static fn (): bool => Schema::hasTable('raccount_webhook_events'),
            'Run `php artisan migrate` after publishing the package migrations.');

        $check('webhook secrets configured (webhooks enabled)', static fn (): bool => config('raccount-sso.webhooks.enabled') !== true
            || count(array_filter((array) config('raccount-sso.webhooks.secrets', []))) > 0,
            'Set RACCOUNT_WEBHOOK_SECRET (rotate with RACCOUNT_WEBHOOK_SECRET_PREVIOUS).');

        $check('server reachable (ping)', static fn (): bool => $client->ping(),
            'The RAccount server did not answer GET /api/v1/ping with {"status":"ok"}.');

        if ($failures > 0) {
            $this->components->error("{$failures} check(s) failed.");

            return self::FAILURE;
        }

        $this->components->info('All RAccount SSO checks passed.');

        return self::SUCCESS;
    }
}
```

Note: `$this->components->task(...)` renders oddly with a truthy closure; if it looks bad, use `$this->line('  ↳ '.$hint, comment style)` — either is acceptable, pick one and keep the test assertions (which only check exit codes) green.

`src/Commands/SyncDirectoryCommand.php`:

```php
<?php

namespace Raccount\Sso\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Raccount\Sso\Directory\DirectorySyncService;
use Raccount\Sso\Exceptions\RaccountException;

final class SyncDirectoryCommand extends Command
{
    protected $signature = 'raccount:directory:sync
                            {--since= : ISO-8601 timestamp; only sync records updated at or after it}';

    protected $description = 'Walk the RAccount directory (M2M) and fire DirectoryUserRetrieved per record';

    public function handle(DirectorySyncService $service): int
    {
        $since = $this->option('since') !== null
            ? CarbonImmutable::parse((string) $this->option('since'))
            : null;

        $count = 0;
        $startedAt = microtime(true);

        try {
            $service->users($since)->each(static function () use (&$count): void {
                $count++;
            });
        } catch (RaccountException $exception) {
            $this->components->error('Directory sync failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Processed %d directory record(s) in %.1fs.',
            $count,
            microtime(true) - $startedAt,
        ));

        return self::SUCCESS;
    }
}
```

`src/Commands/PruneWebhookEventsCommand.php`:

```php
<?php

namespace Raccount\Sso\Commands;

use Illuminate\Console\Command;
use Raccount\Sso\Models\RaccountWebhookEvent;

final class PruneWebhookEventsCommand extends Command
{
    protected $signature = 'raccount:prune-webhooks {--days=30 : Delete events received more than this many days ago}';

    protected $description = 'Prune processed RAccount webhook events';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));

        $deleted = RaccountWebhookEvent::query()
            ->where('received_at', '<', now()->subDays($days))
            ->delete();

        $this->components->info("Deleted {$deleted} webhook event(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
```

Update `src/RaccountSsoServiceProvider.php` `boot()` — add:

```php
        if ($this->app->runningInConsole()) {
            $this->commands([
                \Raccount\Sso\Commands\CheckCommand::class,
                \Raccount\Sso\Commands\SyncDirectoryCommand::class,
                \Raccount\Sso\Commands\PruneWebhookEventsCommand::class,
            ]);
        }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest`
Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add src/Commands src/RaccountSsoServiceProvider.php tests/Feature/CommandsTest.php
git commit -m "feat: raccount:check, directory:sync, and prune-webhooks commands"
```

---

### Task 14: Blade button, README, docs, and release polish

**Files:**
- Create: `src/resources/views/components/button.blade.php`
- Modify: `src/RaccountSsoServiceProvider.php` (view namespace + publish tag `raccount-sso-views`)
- Modify: `README.md` (full rewrite), `CHANGELOG.md` (initial release section)
- Create: `docs/installation.md`, `docs/configuration.md`, `docs/login-flow.md`, `docs/webhooks.md`, `docs/directory-sync.md`, `docs/security.md`, `docs/octane.md`, `docs/upgrading.md`
- Test: `tests/Feature/ButtonTest.php`

**Interfaces:**
- Consumes: route `raccount.login` (Task 9), config `button_label` (Task 2).
- Produces: `<x-raccount::button />` Blade component (props: `label`), rendering an `<a>` to `raccount.login` with merged attributes.

- [ ] **Step 1: Write the failing test**

`tests/Feature/ButtonTest.php`:

```php
<?php

it('renders a link to the sso login route', function (): void {
    $this->withViewErrors([]);

    expect(blade('<x-raccount::button />')->render())
        ->toContain('href="'.route('raccount.login').'"')
        ->toContain('Login with RAccount');
});

it('accepts a custom label and attributes', function (): void {
    $this->withViewErrors([]);

    expect(blade('<x-raccount::button label="Masuk via RAccount" class="btn" />')->render())
        ->toContain('Masuk via RAccount')
        ->toContain('class="btn"');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/ButtonTest.php`
Expected: FAIL — view namespace not registered.

- [ ] **Step 3: Implement the component and provider wiring**

`src/resources/views/components/button.blade.php`:

```blade
@props(['label' => null])

<a {{ $attributes }} href="{{ route('raccount.login') }}">{{ $label ?? config('raccount-sso.button_label', 'Login with RAccount') }}</a>
```

Update `src/RaccountSsoServiceProvider.php` `boot()` — add:

```php
        $this->loadViewsFrom(__DIR__.'/resources/views', 'raccount');

        $this->publishes([
            __DIR__.'/resources/views' => resource_path('views/vendor/raccount'),
        ], 'raccount-sso-views');
```

Run: `vendor/bin/pest tests/Feature/ButtonTest.php` — Expected: PASS.

- [ ] **Step 4: Write the README**

`README.md` (full content):

````markdown
# raccount/laravel-sso

[![run-tests](https://github.com/raccount/laravel-sso/actions/workflows/run-tests.yml/badge.svg)](https://github.com/raccount/laravel-sso/actions/workflows/run-tests.yml)
[![Packagist](https://img.shields.io/packagist/v/raccount/laravel-sso.svg)](https://packagist.org/packages/raccount/laravel-sso)

Single Sign-On client SDK for [RAccount](https://account.reducates.id) — the Reducates ecosystem
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
RACCOUNT_SSO_SERVER_URL=https://account.reducates.id
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

and enable enforcement:

```env
# or set 'middleware' => ['enforce_status' => true] in config/raccount-sso.php
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
contact the maintainers directly — do not open a public issue.

## License

The MIT License (MIT). See [LICENSE](LICENSE) for more information.
````

- [ ] **Step 5: Write the docs/ files**

Each file is complete prose (no placeholders). Required content:

`docs/installation.md` — composer require; publish config/migrations/views; migrate; register client with the RAccount admin (exact info to provide: name, logo ≥64px, redirect URIs HTTPS exact, scopes, confidential); local development with loopback HTTP (`RACCOUNT_SSO_ALLOW_INSECURE=true` only works when `APP_ENV=local`); verification via `php artisan raccount:check`.

`docs/configuration.md` — a table of EVERY config key with type, default, env var, and description, grouped by section (`server`, `client`, `scopes`, `prompt`, `mode`, `routes`, `webhooks`, `directory`, `user`, `redirects`, `exclusive`, `middleware`, `button_label`, `http`). Include the custom resolver example (class implementing `Raccount\Sso\Contracts\UserResolver`, bound via `user.resolver`) and a custom attribute-map example.

`docs/login-flow.md` — sequence diagram (text) of redirect → authorize → consent → callback → resolve → login; session keys used; what happens on `error=access_denied`; the state/PKCE single-use guarantees; logout behaviour (revocation best-effort, no RP-initiated logout — link to the reason in security.md); refresh usage example with `TokenService::refresh()` and the `invalid_grant` → logout contract.

`docs/webhooks.md` — headers table (`X-RAccount-Signature`, `X-RAccount-Event-Id`, `X-RAccount-Event-Type`, `X-RAccount-Timestamp`); payload shape; enabling (`webhooks.enabled`, secret env vars); the at-least-once + idempotent-listener contract; the built-in `UpdateAccountStatus` behaviour; how to write a listener (code sample for `UserSuspended`); secret rotation procedure (set `RACCOUNT_WEBHOOK_SECRET` to the new value and move the old one to `RACCOUNT_WEBHOOK_SECRET_PREVIOUS`, then rotate server-side); response-code semantics (verification failure → 200 silent, processing failure → 500 so the server retries); `raccount:prune-webhooks` scheduling example.

`docs/directory-sync.md` — what directory sync is for (initial backfill + incremental reconciliation); requires a client-credentials client with `sync:read` and directory enablement; config keys; the `DirectoryUserRetrieved` listener example (create/update local users); `php artisan raccount:directory:sync --since=`; cursor ordering by `updated_at` ascending; soft-deleted users never appear (deletion arrives as webhook).

`docs/security.md` — threat model table mapping CSRF (state, hash_equals, single-use), code interception (PKCE S256), MITM (HTTPS enforced server-side, tokens never touch the browser), replay (webhook window + dedupe, refresh rotation + family revocation, single-use auth codes), data at rest (encrypted casts, secrets in env only, redacted logs), open redirect (exact-match redirect URIs, intended-only redirects); notes: no JWKS/discovery (introspection + userinfo instead), access tokens stay valid until expiry after SSO logout (revoke via `raccount.logout`), `invalid_grant` MUST NOT be retried.

`docs/octane.md` — why the package is Octane-safe (config read per call, singleton `raccount-sso.client` stateless, machine tokens in cache store not process memory, session state via Laravel stores); recommendation to use Redis/database sessions in the host app; note about `config:cache` compatibility (no closures in config).

`docs/upgrading.md` — placeholder-free initial content: current version line, upgrade policy (semver, config keys added only with safe defaults), changelog link.

`CHANGELOG.md` — replace the `[Unreleased]` body with a `## 1.0.0 - 2026-09-XX` section listing: initial release (login flow, resolver, token service, webhooks, directory sync, commands, middleware, Blade button).

- [ ] **Step 6: Full verification**

```bash
vendor/bin/pint
vendor/bin/phpstan analyse --no-progress
vendor/bin/pest
```

Expected: Pint clean, Larastan zero errors, full suite green.

- [ ] **Step 7: Commit**

```bash
git add src/resources README.md CHANGELOG.md docs tests/Feature/ButtonTest.php src/RaccountSsoServiceProvider.php
git commit -m "feat: blade login button, documentation, and release polish"
```

---

## Final verification (whole plan)

After Task 14, run from the package root:

```bash
vendor/bin/pest              # full suite green
vendor/bin/pint --test       # clean
vendor/bin/phpstan analyse   # zero errors
php -l src/RaccountSsoServiceProvider.php  # syntax sanity on the most-edited file
git log --oneline            # ~14 feature commits on top of the docs commits
```

Then review the spec's section 5 checklist (`docs/superpowers/specs/2026-09-07-raccount-laravel-sso-design.md`) item by item and confirm each has an implementation: Client (Task 4–5), Flow (Task 9), Resolver (Task 7), Storage (Task 6), Webhooks (Task 11), Directory (Task 12), Diagnostics (Task 13), mode optional/exclusive (Task 2 config + Task 10 middleware), Blade button (Task 14), docs (Task 14).

