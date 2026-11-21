<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around the Pixelforge API.
 *
 * Two behaviours matter here:
 *  - POST /generations is retried on 429/503 (the provider is flaky on purpose),
 *    always with the same Idempotency-Key, so a retry can never double-create
 *    or double-bill a generation. Retry-After is respected and retries are capped.
 *  - Everything else is a plain call.
 */
class PixelforgeClient
{
    private const MAX_SUBMIT_ATTEMPTS = 4;

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
     *  ['ok' => false, 'code' => 'unavailable', ...]               gave up after capped retries
     */
    public function createGeneration(array $params, string $idempotencyKey): array
    {
        $attempt = 0;
        $lastResponse = null;

        while ($attempt < self::MAX_SUBMIT_ATTEMPTS) {
            $attempt++;
            $lastResponse = $this->request()
                ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                ->post('/generations', $params);

            if ($lastResponse->status() === 202) {
                return ['ok' => true, 'generation' => $lastResponse->json()];
            }

            $code = $lastResponse->json('error.code');

            // Retryable provider-side conditions only; respect Retry-After, back off.
            if (in_array($lastResponse->status(), [429, 503], true)) {
                $wait = min((int) $lastResponse->header('Retry-After', 1), 10);
                if ($attempt < self::MAX_SUBMIT_ATTEMPTS) {
                    sleep(max(1, $wait));
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
