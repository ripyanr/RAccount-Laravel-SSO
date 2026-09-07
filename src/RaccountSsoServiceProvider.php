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
