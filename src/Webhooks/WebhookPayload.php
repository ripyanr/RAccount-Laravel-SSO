<?php

namespace Raccount\Sso\Webhooks;

use Carbon\CarbonImmutable;
use Raccount\Sso\Events\UserCreated;
use Raccount\Sso\Events\UserDeleted;
use Raccount\Sso\Events\UserEvent;
use Raccount\Sso\Events\UserReactivated;
use Raccount\Sso\Events\UserSignedOut;
use Raccount\Sso\Events\UserSuspended;
use Raccount\Sso\Events\UserUpdated;

final class WebhookPayload
{
    /**
     * @param  array{type?: string, id?: string}  $actor
     * @param  array<string, mixed>  $data
     * @param  list<string>  $changed
     */
    public function __construct(
        public readonly string $eventId,
        public readonly string $type,
        public readonly ?CarbonImmutable $occurredAt,
        public readonly array $actor,
        public readonly array $data,
        public readonly array $changed,
    ) {}

    /**
     * @param  array<string, mixed>  $body
     */
    public static function fromArray(array $body, string $eventId, string $type): self
    {
        return new self(
            eventId: $eventId,
            type: $type,
            occurredAt: isset($body['occurred_at']) && $body['occurred_at'] !== null
                ? CarbonImmutable::parse((string) $body['occurred_at'])
                : null,
            actor: (array) ($body['actor'] ?? []),
            data: (array) ($body['data'] ?? []),
            changed: array_values((array) ($body['changed'] ?? [])),
        );
    }

    /**
     * @return class-string<UserEvent>|null
     */
    public static function eventClassFor(string $type): ?string
    {
        return match ($type) {
            'user.created' => UserCreated::class,
            'user.updated' => UserUpdated::class,
            'user.suspended' => UserSuspended::class,
            'user.reactivated' => UserReactivated::class,
            'user.deleted' => UserDeleted::class,
            'user.signed_out' => UserSignedOut::class,
            default => null,
        };
    }

    public function sub(): ?string
    {
        $sub = $this->data['sub'] ?? null;

        return is_string($sub) ? $sub : null;
    }
}
