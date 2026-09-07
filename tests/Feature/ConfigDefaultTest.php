<?php

use Raccount\Sso\Client\RaccountClient;
use Raccount\Sso\Exceptions\ConfigurationInvalid;

it('defaults the server base url to the production raccount instance', function (): void {
    $config = require __DIR__.'/../../src/config/raccount-sso.php';

    expect($config['server']['base_url'])->toBe('https://account.reducates.com');
});

it('still rejects an explicitly empty base url at the client level', function (): void {
    // The config default covers normal deployments; an operator who forces the
    // value to null still gets a loud ConfigurationInvalid instead of a
    // request to a garbage host.
    config()->set('raccount-sso.server.base_url', null);

    $this->expectException(ConfigurationInvalid::class);

    app(RaccountClient::class)->ping();
});
