<?php

namespace Raccount\Sso\Webhooks;

use Carbon\CarbonImmutable;
use Throwable;

final class WebhookVerifier
{
    /**
     * @param  list<non-empty-string>  $secrets
     */
    public function __construct(
        private readonly array $secrets,
        private readonly int $tolerance = 300,
    ) {}

    public function verify(string $rawBody, ?string $signatureHeader, ?string $timestampHeader): bool
    {
        if ($signatureHeader === null || ! str_starts_with($signatureHeader, 'sha256=')) {
            return false;
        }

        $provided = strtolower(substr($signatureHeader, 7));

        if (preg_match('/^[0-9a-f]{64}$/', $provided) !== 1) {
            return false;
        }

        foreach ($this->secrets as $secret) {
            $expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);

            if (hash_equals($expected, $signatureHeader)) {
                return $this->timestampWithinWindow($timestampHeader);
            }
        }

        return false;
    }

    private function timestampWithinWindow(?string $timestampHeader): bool
    {
        if ($timestampHeader === null) {
            return false;
        }

        try {
            $timestamp = CarbonImmutable::parse($timestampHeader);
        } catch (Throwable) {
            return false;
        }

        return abs(CarbonImmutable::now('UTC')->diffInSeconds($timestamp)) <= $this->tolerance;
    }
}
