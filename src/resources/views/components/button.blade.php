@props(['label' => null])

<a {{ $attributes }} href="{{ route('raccount.login') }}">{{ $label ?? config('raccount-sso.button_label', 'Login with RAccount') }}</a>
