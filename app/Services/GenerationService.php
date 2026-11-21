<?php

namespace App\Services;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Submit flow: estimate guard, reserve-then-settle hold, Pixelforge create.
 * Settlement of the hold happens later in GenerationState on success/failure.
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

        $result = $this->client->createGeneration([
            'prompt' => $data['prompt'],
            'size' => $data['size'],
            'quality' => $data['quality'],
            'reference_images' => $referenceImages,
            'metadata' => ['generation_id' => (string) $generation->id],
        ], $generation->idempotency_key);

        if ($result['ok']) {
            $remote = $result['generation'];
            $generation->fill([
                'pixelforge_id' => $remote['id'],
                'status' => $remote['status'] ?? 'queued',
                'rates' => $remote['rates'] ?? null,
            ])->save();
        } else {
            $generation->fill([
                'status' => $result['code'] === 'unavailable' ? 'submit_failed' : 'rejected',
                'error_code' => $result['code'],
                'error_message' => $result['message'],
                'reserved_nano' => 0,
            ])->save();

            $user->increment('balance_nano', $estimate['charge_high_nano']);
        }

        return ['ok' => true];
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
