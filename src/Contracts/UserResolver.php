<?php

namespace Raccount\Sso\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Raccount\Sso\Client\Dto\UserInfo;
use Raccount\Sso\Exceptions\AccountLinkageDenied;

interface UserResolver
{
    /**
     * Map a verified RAccount identity onto a local user.
     *
     * @throws AccountLinkageDenied
     */
    public function resolve(UserInfo $userinfo): Authenticatable;
}
