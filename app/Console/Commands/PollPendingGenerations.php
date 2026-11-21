<?php

namespace App\Console\Commands;

use App\Models\Generation;
use App\Services\GenerationState;
use App\Services\PixelforgeClient;
use Illuminate\Console\Command;

/**
 * Webhook delivery is at-least-once but not guaranteed. This is the safety net:
 * every minute we re-check generations that have been waiting a while and apply
 * whatever state Pixelforge reports, through the same code path as webhooks.
 */
class PollPendingGenerations extends Command
{
    protected $signature = 'pixelforge:poll';

    protected $description = 'Re-check generations still waiting on Pixelforge (webhook fallback)';

    public function handle(PixelforgeClient $client): int
    {
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
