<?php

namespace Raccount\Sso\Client\Dto;

use Carbon\CarbonImmutable;

final class IntrospectionResult
{
    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public readonly bool $active,
        public readonly ?string $tokenType,
        public readonly ?string $clientId,
        public readonly array $scopes,
        public readonly ?CarbonImmutable $expiresAt,
        public readonly ?string $sub,
    ) {}

    /**
     * @param  array<string, mixed>  $body
     */
    public static function fromVerdict(array $body): self
    {
        $scope = trim((string) ($body['scope'] ?? ''));

        return new self(
            active: (bool) ($body['active'] ?? false),
            tokenType: isset($body['token_type']) ? (string) $body['token_type'] : null,
            clientId: isset($body['client_id']) ? (string) $body['client_id'] : null,
            scopes: $scope === '' ? [] : (preg_split('/\s+/', $scope) ?: []),
            expiresAt: isset($body['exp']) ? CarbonImmutable::createFromTimestampUTC((int) $body['exp']) : null,
            sub: isset($body['sub']) ? (string) $body['sub'] : null,
        );
    }
}
