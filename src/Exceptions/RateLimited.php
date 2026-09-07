<?php

namespace Raccount\Sso\Exceptions;

final class RateLimited extends RaccountException
{
    public function __construct(
        string $message,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
    }
}
