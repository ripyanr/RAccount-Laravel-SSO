# Contributing

Thanks for considering to contribute to `raccount/laravel-sso`!

## Setting up locally

```bash
git clone <your-fork>
cd laravel-sso
composer install
composer test
```

## Guidelines

- Follow the existing code style (`composer format`, Laravel Pint preset).
- Add or update Pest tests for every change (`composer test`).
- Keep static analysis clean: `composer analyse` (Larastan level 6).
- Write commit messages using the Conventional Commits format.
- Security-relevant issues: do NOT open a public issue; contact the maintainers directly.
