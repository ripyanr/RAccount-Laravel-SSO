<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Raccount\Sso\Models\RaccountAccount;
use Raccount\Sso\Tests\Fixtures\User;

it('redirects to the authorization url with state and pkce in the session', function (): void {
    $response = $this->get('/raccount/redirect');

    $target = $response->headers->get('Location');
    parse_str((string) parse_url((string) $target, PHP_URL_QUERY), $query);

    expect($target)->toStartWith('https://account.test/oauth/authorize')
        ->and($query['state'])->toBe(session('raccount-sso.state'))
        ->and(strlen((string) $query['state']))->toBe(64)
        ->and($query['code_challenge'])->toBe(
            rtrim(strtr(base64_encode(hash('sha256', (string) session('raccount-sso.verifier'), true)), '+/', '-_'), '='),
        )
        ->and($query['code_challenge_method'])->toBe('S256');
});

it('completes the callback flow: login, session regeneration, encrypted tokens', function (): void {
    $this->get('/raccount/redirect');
    $state = session('raccount-sso.state');

    Http::fake([
        'account.test/oauth/token' => Http::response([
            'token_type' => 'Bearer',
            'expires_in' => 900,
            'access_token' => 'access-token-value',
            'refresh_token' => 'refresh-token-value',
            'scope' => 'profile email',
        ]),
        'account.test/api/v1/userinfo' => Http::response([
            'sub' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
            'name' => 'Budi Santoso',
            'email' => 'budi@example.com',
            'email_verified' => true,
        ]),
    ]);

    $this->get('/raccount/callback?code=auth-code&state='.$state)
        ->assertRedirect('/home');

    expect(Auth::check())->toBeTrue()
        ->and(Auth::user()->email)->toBe('budi@example.com');

    $account = RaccountAccount::query()->where('raccount_sub', '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0')->first();

    expect($account)->not->toBeNull()
        ->and($account->access_token)->toBe('access-token-value')
        ->and(DB::table('raccount_accounts')->where('id', $account->id)->value('access_token'))
        ->not->toBe('access-token-value');
});

it('aborts with 419 when the state does not match', function (): void {
    session([
        'raccount-sso.state' => str_repeat('a', 64),
        'raccount-sso.verifier' => str_repeat('v', 43),
    ]);

    $this->get('/raccount/callback?code=x&state=wrong')->assertStatus(419);
});

it('aborts with 419 when the state is missing from the session', function (): void {
    $this->get('/raccount/callback?code=x&state=whatever')->assertStatus(419);
});

it('redirects to the error route when the server reports an oauth error', function (): void {
    Route::get('/login')->name('login');

    $this->get('/raccount/callback?error=access_denied&error_description=User+declined')
        ->assertRedirect('/login')
        ->assertSessionHas('raccount-sso.error');
});

it('logs out, revokes, and destroys the session', function (): void {
    config()->set('raccount-sso.redirects.after_logout', '/bye');

    $user = User::create(['name' => 'Budi', 'email' => 'budi@example.com']);
    RaccountAccount::query()->create([
        'raccount_sub' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
        'user_type' => RaccountAccount::morphTypeFor($user),
        'user_id' => $user->id,
        'status' => RaccountAccount::STATUS_ACTIVE,
        'access_token' => 'at',
        'refresh_token' => 'rt',
    ]);

    Http::fake(['account.test/oauth/revoke' => Http::response()]);

    $this->actingAs($user)->get('/raccount/logout')->assertRedirect('/bye');

    expect(Auth::check())->toBeFalse();

    $account = RaccountAccount::query()->where('raccount_sub', '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0')->first();

    expect($account->refresh_token)->toBeNull();

    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/oauth/revoke'));
});
