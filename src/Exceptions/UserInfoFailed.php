<?php

namespace Raccount\Sso\Exceptions;

final class UserInfoFailed extends RaccountException
{
    public function __construct(
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }
}
