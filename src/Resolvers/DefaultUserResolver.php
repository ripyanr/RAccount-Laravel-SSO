<?php

namespace Raccount\Sso\Resolvers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Raccount\Sso\Client\Dto\UserInfo;
use Raccount\Sso\Contracts\UserResolver;
use Raccount\Sso\Exceptions\AccountLinkageDenied;
use Raccount\Sso\Exceptions\EmailNotVerified;
use Raccount\Sso\Models\RaccountAccount;

class DefaultUserResolver implements UserResolver
{
    public function resolve(UserInfo $userinfo): Authenticatable
    {
        return DB::transaction(function () use ($userinfo): Authenticatable {
            $account = RaccountAccount::query()
                ->where('raccount_sub', $userinfo->sub)
                ->lockForUpdate()
                ->first();

            if ($account instanceof RaccountAccount) {
                /** @var Authenticatable|null $user */
                $user = $account->user;

                if ($user === null) {
                    // Stale link (local user deleted) — start over below.
                    $account->delete();
                } else {
                    if (! $account->isActive()) {
                        throw new AccountLinkageDenied(
                            "The RAccount identity {$userinfo->sub} is {$account->status} on this application.",
                        );
                    }

                    $account->forceFill($this->snapshot($userinfo))->save();

                    return $user;
                }
            }

            if (config('raccount-sso.user.require_verified_email') && $userinfo->emailVerified !== true) {
                throw new EmailNotVerified(
                    'The RAccount identity has no verified email address; cannot link or provision an account.',
                );
            }

            if ($userinfo->email !== null) {
                $existing = $this->findExistingUser($userinfo->email);

                if ($existing !== null) {
                    if (config('raccount-sso.user.auto_link_verified_email') !== true) {
                        throw new AccountLinkageDenied(
                            "A local account already exists for {$userinfo->email}; automatic linking is disabled.",
                        );
                    }

                    if ($userinfo->emailVerified !== true) {
                        throw new AccountLinkageDenied(
                            "A local account already exists for {$userinfo->email}; the RAccount email address is not verified.",
                        );
                    }

                    $this->createAccount($existing, $userinfo);

                    return $existing;
                }
            }

            $user = $this->createLocalUser($userinfo);
            $this->createAccount($user, $userinfo);

            return $user;
        });
    }

    /**
     * @return array<string, string|null>
     */
    private function snapshot(UserInfo $userinfo): array
    {
        return [
            'name' => $userinfo->name,
            'email' => $userinfo->email,
            'picture_url' => $userinfo->picture,
        ];
    }

    private function createAccount(Authenticatable $user, UserInfo $userinfo): RaccountAccount
    {
        return RaccountAccount::query()->updateOrCreate(
            ['raccount_sub' => $userinfo->sub],
            array_merge($this->snapshot($userinfo), [
                'user_type' => RaccountAccount::morphTypeFor($user),
                'user_id' => $user->getAuthIdentifier(),
                'status' => RaccountAccount::STATUS_ACTIVE,
            ]),
        );
    }

    private function findExistingUser(string $email): ?Authenticatable
    {
        $model = (string) config('raccount-sso.user.model');
        $column = (string) config('raccount-sso.user.email_column', 'email');

        /** @var Authenticatable|null $user */
        $user = $model::query()
            ->whereRaw("LOWER({$column}) = ?", [mb_strtolower($email)])
            ->first();

        return $user;
    }

    private function createLocalUser(UserInfo $userinfo): Authenticatable
    {
        $model = (string) config('raccount-sso.user.model');

        $attributes = [];
        foreach ((array) config('raccount-sso.user.attributes', ['name' => 'name', 'email' => 'email']) as $column => $property) {
            $value = $userinfo->{$property} ?? null;
            if ($value !== null) {
                $attributes[$column] = $value;
            }
        }

        /** @var Authenticatable $user */
        $user = $model::create($attributes);

        return $user;
    }
}
