<?php

namespace Raccount\Sso\Listeners;

use Raccount\Sso\Events\UserCreated;
use Raccount\Sso\Events\UserDeleted;
use Raccount\Sso\Events\UserEvent;
use Raccount\Sso\Events\UserReactivated;
use Raccount\Sso\Events\UserSuspended;
use Raccount\Sso\Models\RaccountAccount;

class UpdateAccountStatus
{
    /**
     * Only the status-defining events map to a status. `user.updated` is
     * deliberately absent: profile edits refresh the snapshot without
     * resurrecting an account that was suspended or deleted server-side.
     *
     * @var array<class-string<UserEvent>, string>
     */
    private const STATUS_BY_EVENT = [
        UserCreated::class => RaccountAccount::STATUS_ACTIVE,
        UserReactivated::class => RaccountAccount::STATUS_ACTIVE,
        UserSuspended::class => RaccountAccount::STATUS_SUSPENDED,
        UserDeleted::class => RaccountAccount::STATUS_DELETED,
    ];

    public function handle(UserEvent $event): void
    {
        $sub = $event->payload->sub();

        if ($sub === null) {
            return;
        }

        $account = RaccountAccount::query()->where('raccount_sub', $sub)->first();

        if ($account === null) {
            return;
        }

        $account->forceFill(array_merge(
            array_key_exists($event::class, self::STATUS_BY_EVENT)
                ? ['status' => self::STATUS_BY_EVENT[$event::class]]
                : [],
            $this->snapshot($event->payload->data),
        ))->save();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function snapshot(array $data): array
    {
        $changes = [];

        if (array_key_exists('name', $data)) {
            $changes['name'] = $data['name'];
        }
        if (array_key_exists('email', $data)) {
            $changes['email'] = $data['email'];
        }
        if (array_key_exists('picture', $data)) {
            $changes['picture_url'] = $data['picture'];
        }

        return $changes;
    }
}
