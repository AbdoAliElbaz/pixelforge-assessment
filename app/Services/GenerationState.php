<?php

namespace App\Services;

use App\Jobs\DownloadGenerationImage;
use App\Models\Generation;
use Illuminate\Support\Facades\DB;

/**
 * One place that applies a Pixelforge generation object to our local row.
 * Used by both the webhook job and the polling fallback, so the two paths can
 * never disagree about money or status.
 *
 * Safety rules encoded here:
 *  - Final is final: a row already succeeded/failed never moves backwards,
 *    which makes out-of-order webhook delivery harmless. The whole transition
 *    runs in one transaction under a row lock, so two events processed at the
 *    same time can still only win once.
 *  - Only succeeded generations ever get a cost or a charge.
 *  - The reserved hold is settled exactly once, inside the same transaction as
 *    the state transition: on success the user is refunded (hold - exact
 *    charge); on failure the full hold is refunded, so failures are free.
 *  - The user charge is capped at the hold. The provider cost ledger always
 *    records the exact billed cost from the generation's own rates snapshot;
 *    if the provider's acceptance-time rates price the run above the hold,
 *    we absorb the difference as operator loss instead of charging more than
 *    the user authorized. A settle can therefore never take a balance
 *    negative.
 */
class GenerationState
{
    public static function apply(Generation $generation, array $object): void
    {
        $imageToDownload = null;

        DB::transaction(function () use ($generation, $object, &$imageToDownload) {
            $locked = Generation::whereKey($generation->id)->lockForUpdate()->first();
            if ($locked === null || $locked->isFinal()) {
                return;
            }

            $locked->fill([
                'rates' => $object['rates'] ?? $locked->rates,
            ]);

            match ($object['status'] ?? null) {
                'queued', 'processing' => $locked->fill(['status' => $object['status']]),
                'succeeded' => self::succeed($locked, $object),
                'failed' => self::fail($locked, $object),
                default => null,
            };

            $locked->save();

            if ($locked->status === 'succeeded' && $locked->image_path === null && ! empty($object['image_url'])) {
                $imageToDownload = [$locked->id, $object['image_url']];
            }
        });

        if ($imageToDownload !== null) {
            DownloadGenerationImage::dispatch($imageToDownload[0], $imageToDownload[1]);
        }
    }

    private static function succeed(Generation $generation, array $object): void
    {
        $usage = $object['usage'] ?? [];
        $rates = $object['rates'] ?? $generation->rates ?? [];
        $costNano = Pricing::totalCostNano($usage, $rates);
        $reserved = $generation->reserved_nano ?? 0;
        $chargeNano = min(Pricing::withMarkup($costNano), $reserved);

        $generation->fill([
            'status' => 'succeeded',
            'usage' => $usage,
            'rates' => $rates,
            'provider_cost_nano' => $costNano,
            'charge_nano' => $chargeNano,
            'reserved_nano' => 0,
            'completed_at' => now(),
        ]);

        $user = $generation->user()->lockForUpdate()->first();
        $user->balance_nano += $reserved - $chargeNano;
        $user->save();
    }

    private static function fail(Generation $generation, array $object): void
    {
        $reserved = $generation->reserved_nano ?? 0;

        $generation->fill([
            'status' => 'failed',
            'error_code' => $object['error']['code'] ?? null,
            'error_message' => $object['error']['message'] ?? null,
            'reserved_nano' => 0,
            'completed_at' => now(),
        ]);

        $user = $generation->user()->lockForUpdate()->first();
        $user->balance_nano += $reserved;
        $user->save();
    }
}
