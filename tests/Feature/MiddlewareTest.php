<?php

use Illuminate\Support\Facades\Route;
use Raccount\Sso\Models\RaccountAccount;
use Raccount\Sso\Tests\Fixtures\User;

beforeEach(function (): void {
    Route::get('/login', fn () => 'login')->name('login');
    Route::get('/protected', fn () => 'ok')->middleware(['web', 'raccount.active'])->name('protected');
});

it('lets guests through', function (): void {
    $this->get('/protected')->assertOk();
});

it('lets authenticated users with an active account through', function (): void {
    config()->set('raccount-sso.middleware.enforce_status', true);

    $user = User::create(['name' => 'Budi', 'email' => 'budi@example.com']);
    RaccountAccount::query()->create([
        'raccount_sub' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
        'user_type' => RaccountAccount::morphTypeFor($user),
        'user_id' => $user->id,
        'status' => RaccountAccount::STATUS_ACTIVE,
    ]);

    $this->actingAs($user)->get('/protected')->assertOk();
});

it('logs out users whose raccount account is not active', function (): void {
    config()->set('raccount-sso.middleware.enforce_status', true);

    $user = User::create(['name' => 'Budi', 'email' => 'budi@example.com']);
    RaccountAccount::query()->create([
        'raccount_sub' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
        'user_type' => RaccountAccount::morphTypeFor($user),
        'user_id' => $user->id,
        'status' => RaccountAccount::STATUS_SUSPENDED,
    ]);

    $this->actingAs($user)->get('/protected')->assertRedirect('/login');
});

it('does not enforce status when disabled', function (): void {
    $user = User::create(['name' => 'Budi', 'email' => 'budi@example.com']);
    RaccountAccount::query()->create([
        'raccount_sub' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
        'user_type' => RaccountAccount::morphTypeFor($user),
        'user_id' => $user->id,
        'status' => RaccountAccount::STATUS_SUSPENDED,
    ]);

    $this->actingAs($user)->get('/protected')->assertOk();
});

it('redirects guests away from local auth routes in exclusive mode', function (): void {
    Route::get('/register', fn () => 'register')->name('register');
    Route::middleware('raccount.exclusive')->group(static function (): void {
        Route::get('/login', fn () => 'login')->name('login');
        Route::get('/register', fn () => 'register')->name('register');
    });

    $this->get('/login')->assertRedirect(route('raccount.login'));
    $this->get('/register')->assertRedirect(route('raccount.login'));
});

it('leaves authenticated users on local auth routes in exclusive mode', function (): void {
    Route::middleware('raccount.exclusive')->group(static function (): void {
        Route::get('/login', fn () => 'login')->name('login');
    });

    $user = User::create(['name' => 'Budi', 'email' => 'budi@example.com']);

    $this->actingAs($user)->get('/login')->assertOk();
});
