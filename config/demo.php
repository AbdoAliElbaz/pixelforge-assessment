<?php

/**
 * Assessment/demo guardrails. Open signup + free top-ups are intentional for
 * the take-home, but unbounded usage would drain the shared simulated-provider
 * quota and poison reconciliation. Tune via .env; set DEMO_ALLOW_REGISTRATION
 * to false to lock the app to seeded demo/admin accounts only.
 */
return [

    'allow_registration' => (bool) env('DEMO_ALLOW_REGISTRATION', true),

    /** Lifetime free credit purchases per user (sum of pack sizes in USD). */
    'max_credits_purchased_usd' => (int) env('DEMO_MAX_CREDITS_PURCHASED_USD', 20),

    /** Per-user generation submits allowed per minute. */
    'generations_per_minute' => (int) env('DEMO_GENERATIONS_PER_MINUTE', 6),

];
