# Developing Scout

```bash
composer install
composer test          # PHPUnit via Testbench
npm install && npm run build   # control panel assets (pre-built dist is committed)
```

## Working on it via a local path repository

If you're developing Scout inside a host site that consumes it via a composer path repository (symlinked into `vendor/mlaroy/scout`), `npm run build` only rebuilds this addon's own `public/build/` — it does **not** touch the copy Statamic actually serves from the host's `public/vendor/scout/`. That copy is made once, automatically, on first install, and never again on its own. After any CP asset change, republish it:

```bash
php artisan vendor:publish --tag=scout --force
```

Skip this and the control panel keeps serving whatever was built at install time — no error, just stale JS silently ignoring your changes (e.g. the chat bubble not appearing, or still hitting old routes).

This doesn't affect real installs — Statamic republishes assets automatically the first time the package is installed via Composer.
