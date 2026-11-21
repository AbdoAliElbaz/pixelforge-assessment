<?php

namespace App\Console\Commands;

use App\Models\Generation;
use App\Services\GenerationService;
use App\Services\GenerationState;
use App\Services\PixelforgeClient;
use Illuminate\Console\Command;

/**
 * Webhook delivery is at-least-once but not guaranteed. This is the safety net:
 * every minute we re-check generations that have been waiting a while and apply
 * whatever state Pixelforge reports, through the same code path as webhooks.
 *
 * Also recovers interrupted provider submissions still in pending_submit, always
 * with the stored idempotency key (never a new key, never a blind refund).
 */
class PollPendingGenerations extends Command
{
    protected $signature = 'pixelforge:poll';

    protected $description = 'Re-check generations still waiting on Pixelforge (webhook fallback)';

    public function handle(PixelforgeClient $client, GenerationService $generations): int
    {
        Generation::where('status', 'pending_submit')
            ->where(function ($query) {
                $query->where(function ($q) {
                    $q->whereNotNull('submit_after')
                        ->where('submit_after', '<=', now());
                })->orWhere(function ($q) {
                    $q->whereNull('submit_after')
                        ->where('updated_at', '<', now()->subSeconds(45));
                });
            })
            ->orderBy('id')
            ->limit(20)
            ->get()
            ->each(fn (Generation $generation) => $generations->recoverPendingSubmit($generation));

        Generation::whereIn('status', ['queued', 'processing'])
            ->where('updated_at', '<', now()->subSeconds(45))
            ->limit(20)
            ->get()
            ->each(function (Generation $generation) use ($client) {
                $remote = $client->getGeneration($generation->pixelforge_id);
                if ($remote !== null) {
                    GenerationState::apply($generation, $remote);
                }
            });

        return self::SUCCESS;
    }
}
