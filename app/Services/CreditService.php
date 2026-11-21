<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Demo credit top-ups (no real payment provider), with a lifetime purchase cap. */
class CreditService
{
    public const AMOUNTS_USD = [1, 5, 10];

    /**
     * @return array{ok: true}|array{ok: false, message: string}
     */
    public function purchase(User $user, int $amountUsd): array
    {
        if (! in_array($amountUsd, self::AMOUNTS_USD, true)) {
            throw new InvalidArgumentException('Invalid credit pack.');
        }

        $maxLifetime = (int) config('demo.max_credits_purchased_usd');

        return DB::transaction(function () use ($user, $amountUsd, $maxLifetime) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($locked->credits_purchased_usd + $amountUsd > $maxLifetime) {
                $remaining = max(0, $maxLifetime - $locked->credits_purchased_usd);

                return [
                    'ok' => false,
                    'message' => $remaining === 0
                        ? "Demo top-up limit reached (\${$maxLifetime} lifetime). Use the seeded demo account or wait for a reset."
                        : "Demo top-up limit is \${$maxUsd} lifetime; you can still buy up to \${$remaining}.",
                ];
            }

            // Atomic increment so concurrent clicks never lose a top-up.
            $locked->increment('balance_nano', $amountUsd * 1_000_000_000);
            $locked->increment('credits_purchased_usd', $amountUsd);

            return ['ok' => true];
        });
    }
}
