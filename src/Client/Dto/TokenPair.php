<?php

namespace Raccount\Sso\Client\Dto;

use Carbon\CarbonImmutable;

final class TokenPair
{
    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public readonly string $accessToken,
        public readonly ?string $refreshToken,
        public readonly int $expiresIn,
        public readonly array $scopes,
        public readonly CarbonImmutable $issuedAt,
    ) {}

    /**
     * @param  array<string, mixed>  $body
     */
    public static function fromTokenResponse(array $body, ?CarbonImmutable $now = null): self
    {
        $now ??= CarbonImmutable::now();
        $scope = trim((string) ($body['scope'] ?? ''));

        return new self(
            accessToken: (string) ($body['access_token'] ?? ''),
            refreshToken: isset($body['refresh_token']) ? (string) $body['refresh_token'] : null,
            expiresIn: (int) ($body['expires_in'] ?? 900),
            scopes: $scope === '' ? [] : (preg_split('/\s+/', $scope) ?: []),
            issuedAt: $now,
        );
    }

    public function expiresAt(): CarbonImmutable
    {
        return $this->issuedAt->addSeconds($this->expiresIn);
    }
}
