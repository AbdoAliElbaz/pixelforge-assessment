<?php

namespace App\Services;

use App\Models\User;
use InvalidArgumentException;

/** Demo credit top-ups (no real payment provider). */
class CreditService
{
    public const AMOUNTS_USD = [1, 5, 10];

    public function purchase(User $user, int $amountUsd): void
    {
        if (! in_array($amountUsd, self::AMOUNTS_USD, true)) {
            throw new InvalidArgumentException('Invalid credit pack.');
        }

        // Atomic increment so concurrent clicks never lose a top-up.
        $user->increment('balance_nano', $amountUsd * 1_000_000_000);
    }
}
