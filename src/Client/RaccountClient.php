<?php

namespace Raccount\Sso\Client;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Raccount\Sso\Client\Dto\IntrospectionResult;
use Raccount\Sso\Client\Dto\UserInfo;
use Raccount\Sso\Exceptions\ConfigurationInvalid;
use Raccount\Sso\Exceptions\InvalidGrant;
use Raccount\Sso\Exceptions\RateLimited;
use Raccount\Sso\Exceptions\RequestFailed;
use Raccount\Sso\Exceptions\TokenExchangeFailed;
use Raccount\Sso\Exceptions\UserInfoFailed;

class RaccountClient
{
    public function __construct(
        private readonly Factory $factory,
    ) {}

    public function ping(): bool
    {
        $response = $this->send(
            fn (): Response => $this->http()
                ->acceptJson()
                ->get($this->url('ping_path'))
        );

        return $response->status() === 200 && $response->json('status') === 'ok';
    }

    public function userinfo(string $accessToken): UserInfo
    {
        $response = $this->send(
            fn (): Response => $this->http()
                ->withToken($accessToken)
                ->acceptJson()
                ->get($this->url('userinfo_path'))
        );

        if (in_array($response->status(), [401, 403], true)) {
            throw new UserInfoFailed(
                (string) ($response->json('detail') ?? 'The userinfo endpoint rejected the token.'),
                $response->status(),
            );
        }

        return UserInfo::fromClaimSet((array) $response->json());
    }

    public function introspect(string $token, string $hint = 'access_token'): IntrospectionResult
    {
        $response = $this->send(
            fn (): Response => $this->http()
                ->withBasicAuth($this->clientId(), $this->clientSecret())
                ->asForm()
                ->post($this->url('introspect_path'), [
                    'token' => $token,
                    'token_type_hint' => $hint,
                ])
        );

        $this->assertOAuthResponse($response);

        return IntrospectionResult::fromVerdict((array) $response->json());
    }

    public function revoke(string $token, string $hint = 'refresh_token'): bool
    {
        $response = $this->send(
            fn (): Response => $this->http()
                ->withBasicAuth($this->clientId(), $this->clientSecret())
                ->asForm()
                ->post($this->url('revoke_path'), [
                    'token' => $token,
                    'token_type_hint' => $hint,
                ])
        );

        if (in_array($response->status(), [400, 401], true)) {
            return false;
        }

        return true;
    }

    // ------------------------------------------------------------------
    // Transport
    // ------------------------------------------------------------------

    /**
     * Retry connection errors and 5xx responses with jittered backoff.
     * Never retries 4xx (including invalid_grant) — those are deterministic.
     *
     * @param  Closure(): Response  $attempt
     */
    private function send(Closure $attempt): Response
    {
        $maxAttempts = max(1, (int) config('raccount-sso.http.attempts', 3));
        $backoffMs = max(0, (int) config('raccount-sso.http.backoff_ms', 200));

        for ($attemptNumber = 1; ; $attemptNumber++) {
            try {
                $response = $attempt();
            } catch (ConnectionException $exception) {
                if ($attemptNumber >= $maxAttempts) {
                    throw new RequestFailed(
                        'Could not reach the RAccount server: '.$exception->getMessage(),
                        0,
                        $exception,
                    );
                }
                usleep(($backoffMs * $attemptNumber + random_int(0, 50)) * 1000);

                continue;
            }

            if ($response->status() === 429) {
                $retryAfter = $response->header('Retry-After');

                throw new RateLimited(
                    'RAccount rate limited the request; back off before retrying.',
                    is_numeric($retryAfter) ? (int) $retryAfter : null,
                );
            }

            if (! $response->serverError()) {
                return $response;
            }

            if ($attemptNumber >= $maxAttempts) {
                throw new RequestFailed("RAccount server error (HTTP {$response->status()}).");
            }

            usleep(($backoffMs * $attemptNumber + random_int(0, 50)) * 1000);
        }
    }

    private function http(): PendingRequest
    {
        return $this->factory
            ->timeout((int) config('raccount-sso.http.timeout', 10))
            ->connectTimeout((int) config('raccount-sso.http.connect_timeout', 10));
    }

    private function url(string $pathConfigKey): string
    {
        $base = rtrim((string) config('raccount-sso.server.base_url'), '/');

        if ($base === '') {
            throw new ConfigurationInvalid('raccount-sso.server.base_url is not configured.');
        }

        $scheme = (string) (parse_url($base, PHP_URL_SCHEME) ?: 'https');
        $allowInsecure = config('app.env') === 'local'
            && config('raccount-sso.server.allow_insecure') === true;

        if ($scheme !== 'https' && ! $allowInsecure) {
            throw new ConfigurationInvalid(
                "raccount-sso.server.base_url must use HTTPS (got `{$scheme}://`). Plain HTTP is only allowed in local development with server.allow_insecure enabled.",
            );
        }

        return $base.'/'.ltrim((string) config("raccount-sso.server.{$pathConfigKey}"), '/');
    }

    /**
     * Map RFC 6749 error bodies on /oauth/* to typed exceptions.
     */
    private function assertOAuthResponse(Response $response): void
    {
        if (! in_array($response->status(), [400, 401], true)) {
            return;
        }

        $error = (string) ($response->json('error') ?? 'invalid_request');
        $description = (string) ($response->json('error_description') ?? 'The token endpoint rejected the request.');

        if ($error === 'invalid_grant') {
            throw new InvalidGrant($description);
        }

        throw new TokenExchangeFailed($error, $description);
    }

    private function clientId(): string
    {
        $id = (string) config('raccount-sso.client.id');

        if ($id === '') {
            throw new ConfigurationInvalid('raccount-sso.client.id is not configured.');
        }

        return $id;
    }

    private function clientSecret(): string
    {
        $secret = (string) config('raccount-sso.client.secret');

        if ($secret === '') {
            throw new ConfigurationInvalid('raccount-sso.client.secret is not configured.');
        }

        return $secret;
    }
}
