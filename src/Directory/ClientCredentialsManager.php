<?php

namespace Raccount\Sso\Directory;

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository;
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
