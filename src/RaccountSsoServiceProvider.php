<?php

namespace Raccount\Sso;

use Illuminate\Http\Client\Factory;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Raccount\Sso\Client\RaccountClient;
use Raccount\Sso\Contracts\UserResolver;
use Raccount\Sso\Events\UserCreated;
use Raccount\Sso\Events\UserDeleted;
use Raccount\Sso\Events\UserReactivated;
use Raccount\Sso\Events\UserSuspended;
use Raccount\Sso\Events\UserUpdated;
use Raccount\Sso\Http\Controllers\CallbackController;
use Raccount\Sso\Http\Controllers\LogoutController;
use Raccount\Sso\Http\Controllers\RedirectController;
use Raccount\Sso\Http\Middleware\EnsureRaccountAccountActive;
use Raccount\Sso\Http\Middleware\RedirectAuthRoutesToSso;
use Raccount\Sso\Listeners\UpdateAccountStatus;
use Raccount\Sso\Resolvers\DefaultUserResolver;
use Raccount\Sso\Tokens\TokenService;
use Raccount\Sso\Webhooks\WebhookController;

final class RaccountSsoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/raccount-sso.php', 'raccount-sso');

        $this->app->singleton('raccount-sso.client', static fn ($app): RaccountClient => new RaccountClient(
            $app->make(Factory::class),
        ));

        $this->app->bind(
            UserResolver::class,
            (string) config('raccount-sso.user.resolver', DefaultUserResolver::class),
        );

        $this->app->singleton(TokenService::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/config/raccount-sso.php' => config_path('raccount-sso.php'),
        ], 'raccount-sso-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'raccount-sso-migrations');

        $this->registerRoutes();

        if (config('raccount-sso.webhooks.enabled') === true && config('raccount-sso.webhooks.listeners_enabled') === true) {
            foreach ([UserCreated::class, UserUpdated::class, UserSuspended::class, UserReactivated::class, UserDeleted::class] as $webhookEvent) {
                Event::listen($webhookEvent, UpdateAccountStatus::class);
            }
        }

        $this->app->make(Router::class)->aliasMiddleware(
            'raccount.active',
            EnsureRaccountAccountActive::class,
        );

        $this->app->make(Router::class)->aliasMiddleware(
            'raccount.exclusive',
            RedirectAuthRoutesToSso::class,
        );
    }

    private function registerRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        if (config('raccount-sso.routes.enabled') === true) {
            Route::middleware((array) config('raccount-sso.routes.middleware', ['web']))
                ->prefix((string) config('raccount-sso.routes.prefix', 'raccount'))
                ->group(static function (): void {
                    Route::get('/redirect', RedirectController::class)->name('raccount.login');
                    Route::get('/callback', CallbackController::class)->name('raccount.callback');

                    if (config('raccount-sso.routes.logout_enabled') === true) {
                        Route::get('/logout', LogoutController::class)->name('raccount.logout');
                    }
                });
        }

        if (config('raccount-sso.webhooks.enabled') === true) {
            Route::post(
                (string) config('raccount-sso.webhooks.path', 'raccount/webhook'),
                WebhookController::class,
            )->middleware((array) config('raccount-sso.webhooks.middleware', ['throttle:60,1']));
        }
    }
}
