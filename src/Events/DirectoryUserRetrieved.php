<?php

namespace Raccount\Sso\Events;

use Raccount\Sso\Client\Dto\DirectoryUser;

final class DirectoryUserRetrieved
{
    public function __construct(
        public readonly DirectoryUser $user,
    ) {}
}
