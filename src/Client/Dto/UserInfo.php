<?php

namespace Raccount\Sso\Client\Dto;

use Carbon\CarbonImmutable;

final class UserInfo
{
    public function __construct(
        public readonly string $sub,
        public readonly ?string $name,
        public readonly ?string $picture,
        public readonly ?CarbonImmutable $updatedAt,
        public readonly ?string $email,
        public readonly ?bool $emailVerified,
    ) {}

    /**
     * Claims outside the granted scopes are absent keys — never nulls.
     *
     * @param  array<string, mixed>  $claims
     */
    public static function fromClaimSet(array $claims): self
    {
        return new self(
            sub: (string) ($claims['sub'] ?? ''),
            name: isset($claims['name']) ? (string) $claims['name'] : null,
            picture: isset($claims['picture']) ? (string) $claims['picture'] : null,
            updatedAt: isset($claims['updated_at'])
                ? CarbonImmutable::parse((string) $claims['updated_at'])
                : null,
            email: isset($claims['email']) ? (string) $claims['email'] : null,
            emailVerified: isset($claims['email_verified']) ? (bool) $claims['email_verified'] : null,
        );
    }
}
