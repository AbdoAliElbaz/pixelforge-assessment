# Pixelforge image generator

Senior Product Engineer take-home: a small Laravel + Vue + Inertia app that lets
signed-in users generate images through the Pixelforge API, shows the cost before
they submit, and records every charge exactly: provider cost + 25% markup,
failed generations free, never double-charged.

## Stack

Laravel 13, Inertia, Vue 3, Tailwind (Breeze), SQLite by default, database queue.

## Setup

```bash
composer install
npm install && npm run build
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan storage:link
```

Seeding is optional and for local demo only. It creates two throwaway accounts
(password `password123`): `demo@example.com` (regular user, $5 credits) and
`admin@example.com` (`is_admin`, sees the `/admin` report), plus a few sample
generations so both pages show data immediately.

Create a Pixelforge account and API key, then set in `.env`:

```
PIXELFORGE_API_KEY=...
PIXELFORGE_WEBHOOK_SECRET=...   # the signing secret of that key
```

Each key has its own webhook URL and secret. Use one key per environment.

Run the app:

```bash
php artisan serve
php artisan queue:work          # webhook processing + image downloads
php artisan schedule:work       # polling fallback (pixelforge:poll, every minute)
```

For webhooks in development, expose the app with a tunnel (Expose, ngrok,
Cloudflare Tunnel) and set the tunnel URL as the key's webhook URL in the
Pixelforge dashboard. The dashboard shows every delivery attempt and can resend
events, and `ping` test events are acknowledged and ignored.

## How money stays exact

- Amounts are integer **nanodollars** (1 USD = 1e9 nano). No floats anywhere in
  money math; Pixelforge reports costs to nine decimal places, so nanodollars
  match its exactness.
- Rates ("5.00" per 1M tokens) parse to exact nanodollars per token, so
  cost = tokens × rate is always an exact integer product.
- The final charge uses the generation's **own rates snapshot** (fixed at
  creation), never a later price.
- The user pays provider cost × 5/4 (25% markup), rounded to the nearest
  nanodollar.

## Robustness decisions

- **Idempotent submit.** Every generation gets a UUID idempotency key before we
  talk to Pixelforge; retries on 429/503 reuse it, respect `Retry-After`, and
  stop after 4 attempts. A retried request can never create or bill twice.
- **At-least-once webhooks.** Events dedupe by event id through a unique DB
  index; duplicates are acknowledged and dropped.
- **Out-of-order delivery.** Status only ever moves forward; a finished
  generation never goes back to processing.
- **No guaranteed delivery.** A scheduled command re-polls generations stuck in
  queued/processing and applies state through the same code path as webhooks.
- **Fast ACK.** The webhook endpoint verifies the HMAC signature (raw body,
  constant-time compare, 5-minute timestamp window), records the event, and
  dispatches a queued job, responding well inside the 10-second window.
- **Expiring images.** Signed image URLs die after 24h, so succeeded images are
  downloaded to local storage by a queued job.

## Tests

```bash
php artisan test
```

Covers exact pricing math (including the docs' worked example), signature
verification, duplicate and out-of-order webhook handling, failed generations
staying free, submit retries reusing one idempotency key, credit purchases and
the affordability block, exactly-once balance deduction on completion, and the
admin report's access control and `/v1/usage` reconciliation (match, mismatch
and no-key paths).

## Stretch goals

### Credit balances

- Every user has a `balance_nano` (integer nanodollars, like every other
  amount). "Buy credits" tops it up by fixed packs ($1/$5/$10); it is a fake
  purchase with no payment provider, implemented as one atomic SQL increment
  so concurrent clicks never lose a top-up.
- Reserve-then-settle: at submit, the maximum the request could cost (the high
  end of the estimate) is held from the balance atomically with the generation
  row, in one transaction under the user's row lock. Two concurrent submits
  serialize on that lock, so any number of in-flight generations can never
  overspend the balance.
- The user authorizes the estimate the page displays. Submit re-verifies it:
  if the price moved between display and submit (the pricing cache refreshed
  in between), the submit is rejected with a "review the new estimate" error
  instead of holding a higher amount than the user saw.
- The user charge is capped at the hold. The provider cost ledger always
  records the exact billed cost from the generation's acceptance-time rates
  snapshot; if those rates price the run above the hold (provider raised
  prices between our pricing read and its acceptance), the excess is absorbed
  as operator loss. Charge <= hold <= balance at submit, so a balance can
  never go negative.
- On success the hold is settled to the capped charge (the unused part is
  refunded); on failure, rejection or submit-give-up the full hold is
  refunded, so failures are always free. Settlement happens inside the same
  transaction and row lock as the final state transition in
  `GenerationState`, so a duplicate or concurrent webhook settles once.

### Admin report (`/admin`, `users.is_admin` only)

- Per UTC day and per user: generations, credit spend (what users were
  charged), provider cost (what Pixelforge charged us), and profit.
  Only succeeded generations appear; failed ones are free.
- Grouping uses `completed_at`, because Pixelforge bills a generation on the
  UTC day it completes, so the report reconciles day-for-day with
  `GET /v1/usage` (fetched live, cached one minute). `cost_usd` strings parse
  exactly to nanodollars (`Pricing::usdToNano`); each day shows match/mismatch.
- Without a `PIXELFORGE_API_KEY` the reconciliation section degrades to a note
  and the report still works from our own records.

## Deliberately left open (discussed in the interview)

- Cost display is an honest low–high range, because text and reasoning token
  counts are only known after the run; output and image tokens are exact.
- Submit is synchronous in the request (usually instant); heavy provider-side
  retries would move to a queued job in a larger app.
- A generation stuck in queued/processing forever (provider never completes
  and every webhook and poll is lost) keeps its hold; an operator sweep for
  stale rows would be the next hardening step.
