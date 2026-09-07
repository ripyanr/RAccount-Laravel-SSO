<?php

use Carbon\CarbonImmutable;
use Raccount\Sso\Client\Dto\DirectoryPage;
use Raccount\Sso\Client\Dto\DirectoryUser;
use Raccount\Sso\Client\Dto\IntrospectionResult;
use Raccount\Sso\Client\Dto\TokenPair;
use Raccount\Sso\Client\Dto\UserInfo;

it('builds a token pair from an oauth token response', function (): void {
    $now = CarbonImmutable::parse('2026-09-07T10:00:00Z');
    $pair = TokenPair::fromTokenResponse([
        'token_type' => 'Bearer',
        'expires_in' => 900,
        'access_token' => 'at',
        'refresh_token' => 'rt',
        'scope' => 'profile email',
    ], $now);

    expect($pair->accessToken)->toBe('at')
        ->and($pair->refreshToken)->toBe('rt')
        ->and($pair->expiresIn)->toBe(900)
        ->and($pair->scopes)->toBe(['profile', 'email'])
        ->and($pair->expiresAt()->equalTo($now->addSeconds(900)))->toBeTrue();
});

it('tolerates token responses without refresh token or scope', function (): void {
    $pair = TokenPair::fromTokenResponse(['access_token' => 'at', 'expires_in' => 60]);

    expect($pair->refreshToken)->toBeNull()
        ->and($pair->scopes)->toBe([]);
});

it('builds userinfo with absent claims as null', function (): void {
    $info = UserInfo::fromClaimSet([
        'sub' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
        'name' => 'Budi Santoso',
        'updated_at' => '2026-09-07T00:00:00Z',
    ]);

    expect($info->sub)->toBe('0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0')
        ->and($info->name)->toBe('Budi Santoso')
        ->and($info->email)->toBeNull()
        ->and($info->emailVerified)->toBeNull()
        ->and($info->picture)->toBeNull()
        ->and($info->updatedAt?->toIso8601ZuluString())->toBe('2026-09-07T00:00:00Z');
});

it('builds a directory user and page from records', function (): void {
    $page = DirectoryPage::fromResponse([
        'data' => [
            [
                'sub' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
                'name' => 'Budi',
                'email' => 'budi@example.com',
                'email_verified' => true,
                'status' => 'active',
                'updated_at' => '2026-09-07T00:00:00Z',
            ],
        ],
        'next_cursor' => 'CUR1',
    ]);

    expect($page->data)->toHaveCount(1)
        ->and($page->data[0])->toBeInstanceOf(DirectoryUser::class)
        ->and($page->data[0]->emailVerified)->toBeTrue()
        ->and($page->data[0]->status)->toBe('active')
        ->and($page->nextCursor)->toBe('CUR1');
});

it('builds an introspection verdict', function (): void {
    $result = IntrospectionResult::fromVerdict([
        'active' => true,
        'token_type' => 'access_token',
        'client_id' => '11111111-1111-1111-1111-111111111111',
        'scope' => 'profile email',
        'exp' => 1798760400,
        'sub' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
    ]);

    expect($result->active)->toBeTrue()
        ->and($result->scopes)->toBe(['profile', 'email'])
        ->and($result->sub)->toBe('0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0')
        ->and($result->expiresAt?->getTimestamp())->toBe(1798760400);
});

it('builds an inactive introspection verdict with minimal fields', function (): void {
    $result = IntrospectionResult::fromVerdict(['active' => false]);

    expect($result->active)->toBeFalse()
        ->and($result->sub)->toBeNull()
        ->and($result->expiresAt)->toBeNull();
});
