<?php

namespace Tests\Feature;

use App\Jobs\RecoverPendingSubmit;
use App\Models\Generation;
use App\Models\User;
use App\Services\GenerationState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PendingSubmitRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private array $rates = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    private array $pricing = [
        'object' => 'pricing',
        'rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'tokens' => [
            'characters_per_text_token' => 4,
            'text_token_variance' => 0.15,
            'per_reference_image' => 765,
            'output' => [
                'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
            ],
            'reasoning' => [
                'low' => ['min' => 0, 'max' => 500],
                'medium' => ['min' => 200, 'max' => 2000],
                'high' => ['min' => 1000, 'max' => 8000],
            ],
        ],
    ];

    public function test_connection_failure_leaves_hold_and_poll_recovers_with_same_idempotency_key(): void
    {
        Bus::fake([RecoverPendingSubmit::class]);

        $attempts = 0;
        Http::fake(function ($request) use (&$attempts) {
            if (str_contains($request->url(), '/pricing')) {
                return Http::response($this->pricing);
            }

            $attempts++;
            if ($attempts === 1) {
                throw new ConnectionException('Connection timed out');
            }

            $this->assertTrue($request->hasHeader('Idempotency-Key'));

            return Http::response([
                'id' => 'gen_recovered',
                'status' => 'queued',
                'rates' => $this->rates,
            ], 202);
        });

        $user = User::factory()->create(['balance_nano' => 10_000_000_000]);
        $before = $user->balance_nano;

        $this->actingAs($user)->post('/generate', [
            'prompt' => 'a lighthouse',
            'size' => '1024x1024',
            'quality' => 'medium',
        ])->assertRedirect(route('generate'));

        $generation = Generation::first();
        $this->assertNotNull($generation);
        $this->assertSame('pending_submit', $generation->status);
        $this->assertNull($generation->pixelforge_id);
        $this->assertSame($generation->reserved_nano, $before - $user->fresh()->balance_nano);
        $this->assertSame(1, Generation::count());

        $idempotencyKey = $generation->idempotency_key;
        $hold = $generation->reserved_nano;

        // Make the deferred window due so the poll safety net can recover.
        Generation::whereKey($generation->id)->update(['submit_after' => now()->subSecond()]);

        Artisan::call('pixelforge:poll');

        $generation->refresh();
        $this->assertSame('queued', $generation->status);
        $this->assertSame('gen_recovered', $generation->pixelforge_id);
        $this->assertSame($idempotencyKey, $generation->idempotency_key);
        $this->assertSame($hold, $generation->reserved_nano);
        $this->assertSame(1, Generation::count());
        $this->assertSame(2, $attempts, 'exactly one recovery POST after the failed attempt');

        // ConnectionException attempts are not kept in Http::recorded(); assert the
        // successful recovery reused the same stored key.
        $keys = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), '/generations') && $pair[0]->method() === 'POST')
            ->map(fn ($pair) => $pair[0]->header('Idempotency-Key')[0] ?? null);
        $this->assertSame([$idempotencyKey], $keys->values()->all());
    }

    public function test_response_loss_after_provider_acceptance_does_not_duplicate_or_double_charge(): void
    {
        Bus::fake([RecoverPendingSubmit::class]);

        $attempts = 0;
        Http::fake(function ($request) use (&$attempts) {
            if (str_contains($request->url(), '/pricing')) {
                return Http::response($this->pricing);
            }

            $attempts++;
            if ($attempts === 1) {
                // Provider accepted, but the HTTP response never reached us.
                throw new ConnectionException('Connection reset by peer');
            }

            // Idempotent replay of the accepted generation (already succeeded).
            return Http::response([
                'id' => 'gen_accepted',
                'status' => 'succeeded',
                'rates' => $this->rates,
                'usage' => [
                    'input_text_tokens' => 10,
                    'input_image_tokens' => 0,
                    'output_tokens' => 1100,
                    'reasoning_tokens' => 200,
                ],
                'image_url' => null,
            ], 202, ['Idempotent-Replayed' => 'true']);
        });

        $user = User::factory()->create(['balance_nano' => 10_000_000_000]);
        $startingBalance = $user->balance_nano;

        $this->actingAs($user)->post('/generate', [
            'prompt' => 'a lighthouse',
            'size' => '1024x1024',
            'quality' => 'medium',
        ])->assertRedirect(route('generate'));

        $generation = Generation::first();
        $this->assertSame('pending_submit', $generation->status);
        $this->assertGreaterThan(0, $generation->reserved_nano);
        $held = $generation->reserved_nano;
        $idempotencyKey = $generation->idempotency_key;

        Generation::whereKey($generation->id)->update(['submit_after' => now()->subSecond()]);
        Artisan::call('pixelforge:poll');

        $generation->refresh();
        $user->refresh();

        $this->assertSame(1, Generation::count());
        $this->assertSame($idempotencyKey, $generation->idempotency_key);
        $this->assertSame('gen_accepted', $generation->pixelforge_id);
        $this->assertSame('succeeded', $generation->status);
        $this->assertSame(0, $generation->reserved_nano);
        $this->assertNotNull($generation->charge_nano);
        $this->assertSame(
            $startingBalance - $generation->charge_nano,
            $user->balance_nano,
            'hold settled exactly once to the charge; no duplicate debit',
        );
        $this->assertLessThan($held, $generation->charge_nano);

        // A second recovery must be a no-op: still one row, same charge, same balance.
        $balanceAfter = $user->balance_nano;
        $charge = $generation->charge_nano;
        Artisan::call('pixelforge:poll');
        GenerationState::apply($generation->fresh(), [
            'id' => 'gen_accepted',
            'status' => 'succeeded',
            'rates' => $this->rates,
            'usage' => [
                'input_text_tokens' => 10,
                'input_image_tokens' => 0,
                'output_tokens' => 1100,
                'reasoning_tokens' => 200,
            ],
        ]);

        $this->assertSame(1, Generation::count());
        $this->assertSame($charge, $generation->fresh()->charge_nano);
        $this->assertSame($balanceAfter, $user->fresh()->balance_nano);
        $this->assertSame(2, $attempts);
    }

    public function test_retry_after_greater_than_ten_seconds_defers_full_delay_with_same_key(): void
    {
        Bus::fake();

        Http::fake([
            '*/generations' => Http::response([
                'error' => ['code' => 'rate_limit_exceeded', 'message' => 'Too many requests.'],
            ], 429, ['Retry-After' => '15']),
            '*/pricing' => Http::response($this->pricing),
        ]);

        $user = User::factory()->create(['balance_nano' => 10_000_000_000]);
        $startingBalance = $user->balance_nano;

        $this->actingAs($user)->post('/generate', [
            'prompt' => 'a lighthouse',
            'size' => '1024x1024',
            'quality' => 'low',
        ])->assertRedirect(route('generate'));

        $generation = Generation::first();
        $this->assertSame('pending_submit', $generation->status);
        $this->assertSame('retry_later', $generation->error_code);
        $this->assertNotNull($generation->submit_after);
        $this->assertTrue(
            $generation->submit_after->greaterThanOrEqualTo(now()->addSeconds(14)),
            'submit_after must honour the full Retry-After (15s), not a 10s cap',
        );
        $this->assertSame($startingBalance - $generation->reserved_nano, $user->fresh()->balance_nano);
        $this->assertNull($generation->charge_nano);

        $postCalls = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), '/generations') && $pair[0]->method() === 'POST');
        $this->assertSame(1, $postCalls->count(), 'must not retry early while Retry-After is still running');
        $this->assertSame(
            $generation->idempotency_key,
            $postCalls->first()[0]->header('Idempotency-Key')[0] ?? null,
        );

        Bus::assertDispatched(RecoverPendingSubmit::class, function (RecoverPendingSubmit $job) use ($generation) {
            return $job->generationId === $generation->id
                && $job->delay !== null
                && $job->delay->greaterThanOrEqualTo(now()->addSeconds(14));
        });

        // Poll must not retry early while submit_after is still in the future.
        Artisan::call('pixelforge:poll');
        $this->assertSame(1, collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), '/generations') && $pair[0]->method() === 'POST')
            ->count());
        $this->assertSame('pending_submit', $generation->fresh()->status);
    }
}
