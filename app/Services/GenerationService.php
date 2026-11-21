<?php

namespace App\Services;

use App\Jobs\RecoverPendingSubmit;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Submit flow: estimate guard, reserve-then-settle hold, Pixelforge create.
 * Settlement of the hold happens later in GenerationState on success/failure.
 *
 * Transport failures and long Retry-After delays leave the row in pending_submit
 * with the hold intact. Recovery always reuses the stored idempotency key and
 * original parameters; refunds only happen after a definitive provider outcome.
 */
class GenerationService
{
    public function __construct(
        private Pricing $pricing,
        private PixelforgeClient $client,
    ) {}

    /**
     * @param  array{prompt: string, size: string, quality: string, reference_images?: array<int, string>, displayed_charge_high?: string|null}  $data
     * @return array{ok: true}|array{ok: false, error: string, message: string, charge_high_nano?: int}
     */
    public function submit(User $user, array $data): array
    {
        $referenceImages = array_values(array_filter($data['reference_images'] ?? []));

        $estimate = $this->pricing->estimate(
            $data['prompt'],
            $data['size'],
            $data['quality'],
            count($referenceImages),
        );

        if (($data['displayed_charge_high'] ?? null) !== null
            && $data['displayed_charge_high'] !== Pricing::format($estimate['charge_high_nano'])) {
            return [
                'ok' => false,
                'error' => 'price_changed',
                'message' => 'The price changed while you were editing. The estimate above is up to date; review it and submit again.',
                'charge_high_nano' => $estimate['charge_high_nano'],
            ];
        }

        $generation = DB::transaction(function () use ($user, $data, $referenceImages, $estimate) {
            $locked = User::whereKey($user->id)->lockForUpdate()->first();

            if ($locked->balance_nano < $estimate['charge_high_nano']) {
                return null;
            }

            $locked->decrement('balance_nano', $estimate['charge_high_nano']);

            return $locked->generations()->create([
                'idempotency_key' => (string) Str::uuid(),
                'prompt' => $data['prompt'],
                'size' => $data['size'],
                'quality' => $data['quality'],
                'reference_images' => $referenceImages,
                'status' => 'pending_submit',
                'reserved_nano' => $estimate['charge_high_nano'],
                'estimate_charge_low_nano' => $estimate['charge_low_nano'],
                'estimate_charge_high_nano' => $estimate['charge_high_nano'],
            ]);
        });

        if ($generation === null) {
            return [
                'ok' => false,
                'error' => 'insufficient_balance',
                'message' => 'Not enough credits: this can cost up to $'.Pricing::format($estimate['charge_high_nano'])
                    .' and your available balance is $'.Pricing::format($user->fresh()->balance_nano).'. Buy credits first.',
                'charge_high_nano' => $estimate['charge_high_nano'],
            ];
        }

        $this->attemptProviderSubmit($generation);

        return ['ok' => true];
    }

    /**
     * Re-attempt provider submission for a stuck pending_submit row.
     * Safe to repeat: same idempotency key, settle/refund only after a known outcome.
     */
    public function recoverPendingSubmit(Generation $generation): void
    {
        $generation->refresh();

        if ($generation->status !== 'pending_submit') {
            return;
        }

        if ($generation->submit_after !== null && $generation->submit_after->isFuture()) {
            return;
        }

        $this->attemptProviderSubmit($generation);
    }

    private function attemptProviderSubmit(Generation $generation): void
    {
        $result = $this->client->createGeneration(
            $this->providerParams($generation),
            $generation->idempotency_key,
        );

        $this->applySubmitResult($generation, $result);
    }

    /** @return array{prompt: string, size: string, quality: string, reference_images: array, metadata: array{generation_id: string}} */
    private function providerParams(Generation $generation): array
    {
        return [
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            'reference_images' => array_values($generation->reference_images ?? []),
            'metadata' => ['generation_id' => (string) $generation->id],
        ];
    }

    private function applySubmitResult(Generation $generation, array $result): void
    {
        if ($result['ok']) {
            $remote = $result['generation'];

            DB::transaction(function () use ($generation, $remote) {
                $locked = Generation::whereKey($generation->id)->lockForUpdate()->first();
                if ($locked === null || $locked->status !== 'pending_submit') {
                    return;
                }

                $locked->fill([
                    'pixelforge_id' => $remote['id'],
                    'status' => 'queued',
                    'rates' => $remote['rates'] ?? null,
                    'error_code' => null,
                    'error_message' => null,
                    'submit_after' => null,
                ])->save();
            });

            $generation->refresh();
            if ($generation->status !== 'pending_submit' && $generation->pixelforge_id !== null) {
                GenerationState::apply($generation, $remote);
            }

            return;
        }

        $code = $result['code'] ?? 'unknown';

        if (in_array($code, ['uncertain', 'retry_later'], true)) {
            $delay = $code === 'retry_later'
                ? max(1, (int) ($result['retry_after'] ?? 1))
                : 5;

            $generation->forceFill([
                'submit_after' => now()->addSeconds($delay),
                'error_code' => $code,
                'error_message' => $result['message'] ?? null,
            ])->save();

            RecoverPendingSubmit::dispatch($generation->id)
                ->delay(now()->addSeconds($delay));

            return;
        }

        DB::transaction(function () use ($generation, $result, $code) {
            $locked = Generation::whereKey($generation->id)->lockForUpdate()->first();
            if ($locked === null || $locked->status !== 'pending_submit') {
                return;
            }

            $reserved = $locked->reserved_nano ?? 0;
            $locked->fill([
                'status' => $code === 'unavailable' ? 'submit_failed' : 'rejected',
                'error_code' => $code,
                'error_message' => $result['message'] ?? null,
                'reserved_nano' => 0,
                'submit_after' => null,
            ])->save();

            if ($reserved > 0) {
                $user = User::whereKey($locked->user_id)->lockForUpdate()->first();
                if ($user !== null) {
                    $user->balance_nano += $reserved;
                    $user->save();
                }
            }
        });
    }

    /** Shape a generation row for the Generate page. */
    public function toListItem(Generation $g): array
    {
        return [
            'id' => $g->id,
            'prompt' => $g->prompt,
            'size' => $g->size,
            'quality' => $g->quality,
            'status' => $g->status,
            'error_message' => $g->error_message,
            'charge' => $g->charge_nano !== null ? Pricing::format($g->charge_nano) : null,
            'provider_cost' => $g->provider_cost_nano !== null ? Pricing::format($g->provider_cost_nano) : null,
            'estimate_charge_low' => $g->estimate_charge_low_nano !== null
                ? Pricing::format($g->estimate_charge_low_nano)
                : null,
            'estimate_charge_high' => $g->estimate_charge_high_nano !== null
                ? Pricing::format($g->estimate_charge_high_nano)
                : null,
            'image_url' => $g->image_path ? $this->imageUrl($g->image_path) : null,
            'created_at' => $g->created_at->toIso8601String(),
        ];
    }

    /**
     * Local disks: host-relative /storage/... so APP_URL / artisan-serve port
     * drift cannot 404 the <img>. Object storage: absolute disk URL.
     */
    private function imageUrl(string $path): string
    {
        $disk = config('filesystems.generations');
        $driver = config("filesystems.disks.{$disk}.driver");

        if ($driver === 'local') {
            return '/storage/'.ltrim($path, '/');
        }

        return Storage::disk($disk)->url($path);
    }
}
