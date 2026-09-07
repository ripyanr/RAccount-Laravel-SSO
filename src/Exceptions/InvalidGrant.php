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
