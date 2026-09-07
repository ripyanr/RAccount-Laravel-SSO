<?php

use Raccount\Sso\Webhooks\WebhookVerifier;

it('accepts a correctly signed delivery within the window', function (): void {
    $body = '{"type":"user.updated"}';
    $verifier = new WebhookVerifier(['whsec_one'], 300);

    $signature = 'sha256='.hash_hmac('sha256', $body, 'whsec_one');
    $timestamp = now()->toIso8601ZuluString();

    expect($verifier->verify($body, $signature, $timestamp))->toBeTrue();
});

it('accepts any configured secret (rotation)', function (): void {
    $body = '{"type":"user.updated"}';
    $verifier = new WebhookVerifier(['whsec_old', 'whsec_new'], 300);

    $signature = 'sha256='.hash_hmac('sha256', $body, 'whsec_new');

    expect($verifier->verify($body, $signature, now()->toIso8601ZuluString()))->toBeTrue();
});

it('rejects a bad signature', function (): void {
    $verifier = new WebhookVerifier(['whsec_one'], 300);

    expect($verifier->verify('body', 'sha256='.str_repeat('0', 64), now()->toIso8601ZuluString()))->toBeFalse();
});

it('rejects malformed signature headers', function (): void {
    $verifier = new WebhookVerifier(['whsec_one'], 300);

    expect($verifier->verify('body', null, now()->toIso8601ZuluString()))->toBeFalse()
        ->and($verifier->verify('body', 'sha256=not-hex', now()->toIso8601ZuluString()))->toBeFalse()
        ->and($verifier->verify('body', 'md5='.str_repeat('0', 64), now()->toIso8601ZuluString()))->toBeFalse();
});

it('rejects stale timestamps', function (): void {
    $body = 'body';
    $verifier = new WebhookVerifier(['whsec_one'], 300);
    $signature = 'sha256='.hash_hmac('sha256', $body, 'whsec_one');
    $stale = now()->subMinutes(10)->toIso8601ZuluString();

    expect($verifier->verify($body, $signature, $stale))->toBeFalse();
});

it('rejects unparseable timestamps', function (): void {
    $body = 'body';
    $verifier = new WebhookVerifier(['whsec_one'], 300);
    $signature = 'sha256='.hash_hmac('sha256', $body, 'whsec_one');

    expect($verifier->verify($body, $signature, 'not-a-date'))->toBeFalse()
        ->and($verifier->verify($body, $signature, null))->toBeFalse();
});
