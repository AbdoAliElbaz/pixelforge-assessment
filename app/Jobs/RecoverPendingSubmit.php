<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\GenerationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Deferred / backup re-attempt of POST /generations for a pending_submit row.
 * Always reuses the stored idempotency key and original parameters.
 */
class RecoverPendingSubmit implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 60, 120];

    public int $uniqueFor = 120;

    public function __construct(public int $generationId) {}

    public function uniqueId(): string
    {
        return (string) $this->generationId;
    }

    public function handle(GenerationService $generations): void
    {
        $generation = Generation::find($this->generationId);
        if ($generation === null) {
            return;
        }

        $generations->recoverPendingSubmit($generation);
    }
}
