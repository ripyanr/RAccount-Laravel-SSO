<?php

namespace Raccount\Sso;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;
use Raccount\Sso\Client\RaccountClient;

final class RaccountSsoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/raccount-sso.php', 'raccount-sso');

        $this->app->singleton('raccount-sso.client', static fn ($app): RaccountClient => new RaccountClient(
            $app->make(Factory::class),
        ));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/config/raccount-sso.php' => config_path('raccount-sso.php'),
        ], 'raccount-sso-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'raccount-sso-migrations');
    }
}
