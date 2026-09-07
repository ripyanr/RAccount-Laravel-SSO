<?php

namespace Raccount\Sso\Exceptions;

class TokenExchangeFailed extends RaccountException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly string $errorDescription,
    ) {
        parent::__construct("RAccount token endpoint rejected the request: [{$errorCode}] {$errorDescription}");
    }
}
