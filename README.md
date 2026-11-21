# Pixelforge assessment

Laravel + Vue (Inertia) app for generating images via the Pixelforge API, with
pre-submit cost estimates, credit balances, webhook handling, and an admin
usage/profit report.

## Stack

Laravel 13, Inertia, Vue 3, Tailwind (Breeze), SQLite locally (MySQL in production), database queue.

## Local setup

```bash
composer install
npm install && npm run build
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan storage:link
```

`storage:link` is required so generation images under `storage/app/public` are served at `/storage/...`.
The app also creates the symlink on boot when it is missing and `GENERATIONS_DISK` is local.
If web and queue workers do not share a filesystem, point `GENERATIONS_DISK` at a public object-storage disk instead.

Add your Pixelforge credentials to `.env`:

```
PIXELFORGE_API_KEY=...
PIXELFORGE_WEBHOOK_SECRET=...
```

Run:

```bash
php artisan serve
php artisan queue:work
php artisan schedule:work
```

Webhook URL (local tunnel or production): `POST /webhooks/pixelforge`

Jobs also complete via the `pixelforge:poll` scheduler fallback when webhooks are not delivered (common locally without a public tunnel).

## Demo accounts (after seed)

| Email | Password | Role |
|---|---|---|
| `demo@example.com` | `password123` | User ($5 credits) |
| `admin@example.com` | `password123` | Admin (`/admin`) |

Seeded generations are marked `is_fixture` and excluded from admin spend/reconciliation so they do not look like real provider usage.

Open signup and free top-ups are intentional for the assessment. Caps (env-tunable):

- `DEMO_ALLOW_REGISTRATION` — set `false` to lock to seeded accounts
- `DEMO_MAX_CREDITS_PURCHASED_USD` — lifetime free top-up cap per user (default 20)
- `DEMO_GENERATIONS_PER_MINUTE` — submit rate limit (default 6)

## Tests

```bash
php artisan test
```
