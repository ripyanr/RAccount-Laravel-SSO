<?php

use Raccount\Sso\Flow\Pkce;

it('generates valid pkce verifiers', function (): void {
    for ($i = 0; $i < 5; $i++) {
        $verifier = Pkce::verifier();

        expect(strlen($verifier))->toBeGreaterThanOrEqual(43)
            ->and(strlen($verifier))->toBeLessThanOrEqual(128)
            ->and($verifier)->toMatch('/^[A-Za-z0-9_-]+$/');
    }
});

it('generates unique verifiers', function (): void {
    expect(Pkce::verifier())->not->toBe(Pkce::verifier());
});

it('derives the s256 challenge deterministically', function (): void {
    $challenge = Pkce::challenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk');

    expect($challenge)->toBe('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM')
        ->and($challenge)->toMatch('/^[A-Za-z0-9_-]+$/');
});
