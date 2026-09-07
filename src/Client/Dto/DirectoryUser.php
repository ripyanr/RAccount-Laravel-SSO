<?php

namespace Raccount\Sso\Client\Dto;

use Carbon\CarbonImmutable;

final class DirectoryUser
{
    public function __construct(
        public readonly string $sub,
        public readonly string $name,
        public readonly string $email,
        public readonly bool $emailVerified,
        public readonly string $status,
        public readonly CarbonImmutable $updatedAt,
    ) {}

    /**
     * @param  array<string, mixed>  $record
     */
    public static function fromRecord(array $record): self
    {
        return new self(
            sub: (string) ($record['sub'] ?? ''),
            name: (string) ($record['name'] ?? ''),
            email: (string) ($record['email'] ?? ''),
            emailVerified: (bool) ($record['email_verified'] ?? false),
            status: (string) ($record['status'] ?? 'active'),
            updatedAt: CarbonImmutable::parse((string) ($record['updated_at'] ?? 'now')),
        );
    }
}
