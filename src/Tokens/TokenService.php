<?php

namespace Raccount\Sso\Tokens;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Raccount\Sso\Client\Dto\TokenPair;
use Raccount\Sso\Client\Dto\UserInfo;
use Raccount\Sso\Client\RaccountClient;
use Raccount\Sso\Exceptions\InvalidGrant;
use Raccount\Sso\Exceptions\RaccountException;
use Raccount\Sso\Models\RaccountAccount;

class TokenService
{
    public function __construct(
        private readonly RaccountClient $client,
    ) {}

    public function storeFor(Authenticatable $user, UserInfo $userinfo, TokenPair $tokens): RaccountAccount
    {
        return DB::transaction(function () use ($user, $userinfo, $tokens): RaccountAccount {
            /** @var RaccountAccount $account */
            $account = RaccountAccount::query()->updateOrCreate(
                ['raccount_sub' => $userinfo->sub],
                [
                    'user_type' => RaccountAccount::morphTypeFor($user),
                    'user_id' => $user->getAuthIdentifier(),
                    'email' => $userinfo->email,
                    'name' => $userinfo->name,
                    'picture_url' => $userinfo->picture,
                    'scopes' => $tokens->scopes,
                    'access_token' => $tokens->accessToken,
                    'refresh_token' => $tokens->refreshToken,
                    'access_expires_at' => $tokens->expiresAt(),
                    'status' => RaccountAccount::STATUS_ACTIVE,
                    'last_login_at' => now(),
                ],
            );

            return $account;
        });
    }

    /**
     * Rotate the refresh token. The server invalidates the old refresh token
     * on every use; an invalid_grant means the family was revoked — the
     * caller MUST log the user out and start a new authorization flow.
     *
     * @throws InvalidGrant
     */
    public function refresh(RaccountAccount $account): TokenPair
    {
        if ($account->refresh_token === null) {
            throw new InvalidGrant('No refresh token is stored for this account.');
        }

        try {
            $tokens = $this->client->refresh($account->refresh_token);
        } catch (InvalidGrant $exception) {
            $this->clearTokens($account);

            throw $exception;
        }

        $account->forceFill([
            'access_token' => $tokens->accessToken,
            'refresh_token' => $tokens->refreshToken,
            'access_expires_at' => $tokens->expiresAt(),
        ])->save();

        return $tokens;
    }

    /**
     * Best-effort revocation of both tokens; local secrets are dropped
     * regardless of the server outcome.
     */
    public function revokeAll(RaccountAccount $account): void
    {
        try {
            if ($account->refresh_token !== null) {
                $this->client->revoke($account->refresh_token, 'refresh_token');
            }

            if ($account->access_token !== null) {
                $this->client->revoke($account->access_token, 'access_token');
            }
        } catch (RaccountException $exception) {
            Log::warning('raccount-sso: token revocation failed.', ['exception' => (string) $exception]);
        }

        $this->clearTokens($account);
    }

    private function clearTokens(RaccountAccount $account): void
    {
        $account->forceFill([
            'access_token' => null,
            'refresh_token' => null,
            'access_expires_at' => null,
        ])->save();
    }
}
