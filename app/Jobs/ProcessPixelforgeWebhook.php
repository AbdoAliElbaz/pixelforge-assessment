<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Services\GenerationState;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessPixelforgeWebhook implements ShouldQueue
{
    use Queueable;

    /** Transient DB/worker failures should not drop a verified webhook permanently. */
    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [5, 15, 30, 60];

    public function __construct(public int $webhookEventId) {}

    public function handle(): void
    {
        $event = WebhookEvent::find($this->webhookEventId);
        if ($event === null || $event->processed_at !== null) {
            return;
        }

        $object = $event->payload['data']['object'] ?? [];

        // Only status events for generations we created change state.
        // 'ping' test events and unknown generations are acknowledged and dropped.
        if (in_array($event->type, ['generation.processing', 'generation.succeeded', 'generation.failed'], true)) {
            $generation = Generation::where('pixelforge_id', $object['id'] ?? '')->first();
            if ($generation !== null) {
                GenerationState::apply($generation, $object);
            }
        }

        $event->forceFill(['processed_at' => now()])->save();
    }
}
