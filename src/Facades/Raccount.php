<?php

namespace Raccount\Sso\Facades;

use Illuminate\Support\Facades\Facade;
use Raccount\Sso\Client\Dto\DirectoryPage;
use Raccount\Sso\Client\Dto\IntrospectionResult;
use Raccount\Sso\Client\Dto\TokenPair;
use Raccount\Sso\Client\Dto\UserInfo;
use Raccount\Sso\Client\RaccountClient;

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
 * @see RaccountClient
 */
final class Raccount extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'raccount-sso.client';
    }
}
