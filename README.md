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

## Demo accounts (after seed)

| Email | Password | Role |
|---|---|---|
| `demo@example.com` | `password123` | User ($5 credits) |
| `admin@example.com` | `password123` | Admin (`/admin`) |

## Tests

```bash
php artisan test
```
