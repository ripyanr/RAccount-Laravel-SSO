<?php

namespace Raccount\Sso\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Raccount\Sso\RaccountSsoServiceProvider;
use Raccount\Sso\Tests\Fixtures\User;

abstract class TestCase extends OrchestraTestCase
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [RaccountSsoServiceProvider::class];
    }

    /**
     * @param  Application  $app
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

        // The webhook route and its built-in listeners are registered when the
        // package provider boots, which happens before the test body runs, so
        // webhooks must be enabled here rather than from a helper.
        $app['config']->set('raccount-sso.webhooks.enabled', true);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
