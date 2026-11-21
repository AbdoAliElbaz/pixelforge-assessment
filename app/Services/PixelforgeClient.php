<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around the Pixelforge API.
 *
 * Two behaviours matter here:
 *  - POST /generations is retried on 429/503 (the provider is flaky on purpose),
 *    always with the same Idempotency-Key, so a retry can never double-create
 *    or double-bill a generation. Retry-After is respected in full; short waits
 *    sleep inline, longer waits are returned so the caller can defer.
 *  - Connection/timeout failures return an "uncertain" result: the provider may
 *    already have accepted the request, so callers must retry the same key
 *    rather than refunding or minting a new key.
 */
class PixelforgeClient
{
    private const MAX_SUBMIT_ATTEMPTS = 4;

    /** Inline sleeps above this are deferred to a queued retry instead. */
    private const MAX_INLINE_RETRY_AFTER = 10;

    private function request(): PendingRequest
    {
        return Http::baseUrl(config('services.pixelforge.base_url'))
            ->withToken(config('services.pixelforge.api_key'))
            ->acceptJson()
            ->asJson();
    }

    /**
     * Submit a generation. Returns a result array:
     *  ['ok' => true, 'generation' => [...]]                       accepted (202, maybe idempotent replay)
     *  ['ok' => false, 'code' => 'content_policy_violation', ...]  rejected, nothing created
     *  ['ok' => false, 'code' => 'retry_later', 'retry_after' => n] provider asked for a long wait
     *  ['ok' => false, 'code' => 'uncertain', ...]                  transport failure; outcome unknown
     *  ['ok' => false, 'code' => 'unavailable', ...]               gave up after capped short retries
     */
    public function createGeneration(array $params, string $idempotencyKey): array
    {
        $attempt = 0;
        $lastResponse = null;

        while ($attempt < self::MAX_SUBMIT_ATTEMPTS) {
            $attempt++;

            try {
                $lastResponse = $this->request()
                    ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                    ->post('/generations', $params);
            } catch (ConnectionException) {
                return [
                    'ok' => false,
                    'code' => 'uncertain',
                    'message' => 'The connection to Pixelforge was interrupted before we could confirm acceptance. We will retry the same request; you will not be charged twice.',
                ];
            }

            if ($lastResponse->status() === 202) {
                return ['ok' => true, 'generation' => $lastResponse->json()];
            }

            $code = $lastResponse->json('error.code');

            // Retryable provider-side conditions only; respect Retry-After in full.
            if (in_array($lastResponse->status(), [429, 503], true)) {
                $wait = max(1, (int) $lastResponse->header('Retry-After', 1));

                // Do not truncate long delays or retry early: surface for deferred retry.
                if ($wait > self::MAX_INLINE_RETRY_AFTER) {
                    return [
                        'ok' => false,
                        'code' => 'retry_later',
                        'retry_after' => $wait,
                        'message' => $lastResponse->json('error.message') ?? 'Pixelforge asked us to wait before retrying.',
                    ];
                }

                if ($attempt < self::MAX_SUBMIT_ATTEMPTS) {
                    sleep($wait);

                    continue;
                }

                return ['ok' => false, 'code' => 'unavailable', 'message' => $lastResponse->json('error.message') ?? 'Pixelforge is unavailable right now. You were not charged; please try again.'];
            }

            // Validation, content policy, and anything else: nothing was created.
            return ['ok' => false, 'code' => $code ?? 'unknown', 'message' => $lastResponse->json('error.message') ?? 'Request rejected.'];
        }

        return ['ok' => false, 'code' => 'unavailable', 'message' => 'Pixelforge is unavailable right now. You were not charged; please try again.'];
    }

    public function getGeneration(string $pixelforgeId): ?array
    {
        $response = $this->request()->get("/generations/{$pixelforgeId}");

        return $response->successful() ? $response->json() : null;
    }

    /**
     * Provider-side daily costs for this API key (GET /v1/usage), for the admin
     * reconciliation report. Returns null when no key is configured or the call
     * fails; the report still works from our own records without it.
     */
    public function usage(?string $startDate = null, ?string $endDate = null): ?array
    {
        if (empty(config('services.pixelforge.api_key'))) {
            return null;
        }

        $response = $this->request()->get('/usage', array_filter([
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]));

        return $response->successful() ? $response->json() : null;
    }

    /** Download the signed image URL so it outlives its 24h expiry. */
    public function downloadImage(string $url): ?string
    {
        $response = Http::timeout(20)->get($url);

        return $response->successful() ? $response->body() : null;
    }
}
