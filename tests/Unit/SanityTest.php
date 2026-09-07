<?php

use Illuminate\Support\Facades\Schema;

it('boots the test harness with an app key and sqlite', function (): void {
    expect(config('app.key'))->toStartWith('base64:')
        ->and(config('database.default'))->toBe('testing')
        ->and(Schema::hasTable('users'))->toBeTrue();
});
