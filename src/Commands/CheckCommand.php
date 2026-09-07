<?php

namespace Raccount\Sso\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Raccount\Sso\Client\RaccountClient;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Throwable;

final class CheckCommand extends Command
{
    protected $signature = 'raccount:check';

    protected $description = 'Verify the RAccount SSO configuration, database, and connectivity';

    public function handle(RaccountClient $client): int
    {
        $failures = 0;

        $check = function (string $label, callable $check, string $hint) use (&$failures): void {
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
                $this->line('  ↳ '.$hint, 'comment');
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
            'Set RACCOUNT_SSO_WEBHOOK_SECRET (rotate with RACCOUNT_SSO_WEBHOOK_SECRET_PREVIOUS).');

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
