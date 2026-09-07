<?php

it('renders a link to the sso login route', function (): void {
    $this->withViewErrors([]);

    expect((string) $this->blade('<x-raccount::button />'))
        ->toContain('href="'.route('raccount.login').'"')
        ->toContain('Login with RAccount');
});

it('accepts a custom label and attributes', function (): void {
    $this->withViewErrors([]);

    expect((string) $this->blade('<x-raccount::button label="Masuk via RAccount" class="btn" />'))
        ->toContain('Masuk via RAccount')
        ->toContain('class="btn"');
});
