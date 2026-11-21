<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use App\Services\GenerationState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BalanceTest extends TestCase
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

    public function test_buy_credits_adds_exact_whole_dollar_amounts(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/credits', ['amount' => 5])->assertRedirect();
        $this->actingAs($user)->post('/credits', ['amount' => 1])->assertRedirect();

        $user->refresh();
        $this->assertSame(6_000_000_000, $user->balance_nano);
        $this->assertSame(6, $user->credits_purchased_usd);
    }

    public function test_buy_credits_rejects_amounts_outside_the_packs(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/credits', ['amount' => 3])->assertSessionHasErrors('amount');

        $this->assertSame(0, $user->fresh()->balance_nano);
    }

    public function test_buy_credits_enforces_lifetime_demo_cap(): void
    {
        config(['demo.max_credits_purchased_usd' => 10]);
        $user = User::factory()->create(['credits_purchased_usd' => 10]);

        $this->actingAs($user)->post('/credits', ['amount' => 1])->assertSessionHasErrors('amount');

        $this->assertSame(0, $user->fresh()->balance_nano);
        $this->assertSame(10, $user->fresh()->credits_purchased_usd);
    }

    public function test_submit_is_blocked_when_balance_cannot_cover_the_maximum_estimate(): void
    {
        Http::fake(['*/pricing' => Http::response($this->pricing)]);

        $user = User::factory()->create(['balance_nano' => 0]);

        $this->actingAs($user)->post('/generate', [
            'prompt' => 'a lighthouse',
            'size' => '1024x1024',
            'quality' => 'medium',
        ])->assertSessionHasErrors('balance');

        // Nothing was created locally and Pixelforge was never called.
        $this->assertDatabaseCount('generations', 0);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/generations'));
    }

    public function test_successful_completion_settles_the_hold_to_the_exact_charge_once(): void
    {
        $user = User::factory()->create(['balance_nano' => 5_000_000_000]);
        $generation = Generation::create([
            'user_id' => $user->id,
            'pixelforge_id' => 'gen_bal1',
            'idempotency_key' => 'key-bal1',
            'prompt' => 'a lighthouse',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png'],
            'status' => 'processing',
            'rates' => $this->rates,
            // Held at submit: the maximum charge from the docs' worked example.
            'reserved_nano' => 467_343_750,
        ]);
        // Simulate the submit-time hold.
        $user->decrement('balance_nano', 467_343_750);

        // Actual run came in below the maximum (reasoning 3184, not 8000):
        // exact cost $0.313675, exact charge $0.39209375.
        $object = [
            'id' => 'gen_bal1',
            'status' => 'succeeded',
            'usage' => [
                'input_text_tokens' => 115,
                'input_image_tokens' => 1530,
                'output_tokens' => 6450,
                'reasoning_tokens' => 3184,
            ],
            'rates' => $this->rates,
        ];

        GenerationState::apply($generation, $object);
        GenerationState::apply($generation->fresh(), $object); // duplicate delivery

        $generation->refresh();
        $this->assertSame('succeeded', $generation->status);
        $this->assertSame(392_093_750, $generation->charge_nano);
        $this->assertSame(0, $generation->reserved_nano);
        $this->assertNotNull($generation->completed_at);
        // 5.0 - 0.39209375 exactly; the unused 0.07525 of the hold was refunded once.
        $this->assertSame(5_000_000_000 - 392_093_750, $user->fresh()->balance_nano);
    }

    public function test_failed_completion_refunds_the_full_hold(): void
    {
        $user = User::factory()->create(['balance_nano' => 5_000_000_000]);
        $generation = Generation::create([
            'user_id' => $user->id,
            'pixelforge_id' => 'gen_bal2',
            'idempotency_key' => 'key-bal2',
            'prompt' => 'a lighthouse',
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_images' => [],
            'status' => 'processing',
            'rates' => $this->rates,
            'reserved_nano' => 100_000_000,
        ]);
        // Simulate the submit-time hold.
        $user->decrement('balance_nano', 100_000_000);

        GenerationState::apply($generation, [
            'id' => 'gen_bal2',
            'status' => 'failed',
            'error' => ['code' => 'generation_failed', 'message' => 'No image.'],
        ]);

        $this->assertSame('failed', $generation->refresh()->status);
        $this->assertNull($generation->charge_nano);
        $this->assertSame(0, $generation->reserved_nano);
        $this->assertSame(5_000_000_000, $user->fresh()->balance_nano);
    }

    public function test_pending_generations_cannot_overspend_the_balance(): void
    {
        // High estimate for this request is exactly 86,275,000 nano ($0.086275).
        // The balance covers one reservation, not two.
        Http::fake([
            '*/generations' => Http::response([
                'id' => 'gen_first', 'status' => 'queued', 'rates' => $this->rates,
            ], 202),
            '*/pricing' => Http::response($this->pricing),
        ]);

        $user = User::factory()->create(['balance_nano' => 86_275_000]);

        $payload = [
            'prompt' => 'a lighthouse',
            'size' => '1024x1024',
            'quality' => 'medium',
        ];

        $this->actingAs($user)->post('/generate', $payload)->assertRedirect(route('generate'));
        $this->assertSame(0, $user->fresh()->balance_nano);
        $this->assertDatabaseHas('generations', ['reserved_nano' => 86_275_000]);

        // Second submit while the first is still in flight: blocked, nothing created.
        $this->actingAs($user)->post('/generate', $payload)->assertSessionHasErrors('balance');
        $this->assertDatabaseCount('generations', 1);

        $generationsCalls = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), '/generations'));
        $this->assertSame(1, $generationsCalls->count(), 'Pixelforge must only see the funded submit');
    }

    public function test_rejected_submit_refunds_the_hold(): void
    {
        Http::fake([
            '*/generations' => Http::response([
                'error' => ['code' => 'content_policy_violation', 'message' => 'Rejected.'],
            ], 400),
            '*/pricing' => Http::response($this->pricing),
        ]);

        $user = User::factory()->create(['balance_nano' => 1_000_000_000]);

        $this->actingAs($user)->post('/generate', [
            'prompt' => '[blocked] something',
            'size' => '1024x1024',
            'quality' => 'medium',
        ]);

        $this->assertDatabaseHas('generations', ['status' => 'rejected', 'reserved_nano' => 0]);
        $this->assertSame(1_000_000_000, $user->fresh()->balance_nano);
    }

    public function test_charge_is_capped_at_the_hold_when_provider_rates_rise_before_acceptance(): void
    {
        // Estimate/hold computed from the current pricing (R1). The provider
        // accepts with higher rates (R2), so the acceptance-time snapshot would
        // price the run above the hold. The user must never pay more than held;
        // the excess provider cost is absorbed as operator loss.
        $user = User::factory()->create(['balance_nano' => 21_825_000]); // exactly the hold
        $higherRates = [
            'input_text_tokens' => '10.00',
            'input_image_tokens' => '20.00',
            'output_tokens' => '80.00',
            'reasoning_tokens' => '25.00',
        ];
        $generation = Generation::create([
            'user_id' => $user->id,
            'pixelforge_id' => 'gen_cap1',
            'idempotency_key' => 'key-cap1',
            'prompt' => 'test',
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_images' => [],
            'status' => 'processing',
            'rates' => $higherRates, // snapshot stored from the 202 acceptance
            'reserved_nano' => 21_825_000,
        ]);
        $user->decrement('balance_nano', 21_825_000); // the submit-time hold

        // Actual run at the doubled rates: exact cost $0.03491, markup charge
        // would be $0.0436375, above the $0.021825 hold.
        GenerationState::apply($generation, [
            'id' => 'gen_cap1',
            'status' => 'succeeded',
            'usage' => [
                'input_text_tokens' => 1,
                'input_image_tokens' => 0,
                'output_tokens' => 280,
                'reasoning_tokens' => 500,
            ],
            'rates' => $higherRates,
        ]);

        $generation->refresh();
        // Provider cost ledger stays exact; the user charge is capped at the hold.
        $this->assertSame(34_910_000, $generation->provider_cost_nano);
        $this->assertSame(21_825_000, $generation->charge_nano);
        $this->assertSame(0, $user->fresh()->balance_nano, 'balance lands at exactly zero, never negative');
    }

    public function test_duplicate_settlement_with_capped_charge_refunds_once(): void
    {
        $user = User::factory()->create(['balance_nano' => 21_825_000]);
        $generation = Generation::create([
            'user_id' => $user->id,
            'pixelforge_id' => 'gen_cap2',
            'idempotency_key' => 'key-cap2',
            'prompt' => 'test',
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_images' => [],
            'status' => 'processing',
            'rates' => $this->rates,
            'reserved_nano' => 21_825_000,
        ]);
        $user->decrement('balance_nano', 21_825_000);

        // Below-maximum actual run: exact cost $0.015705, charge $0.01963125.
        $object = [
            'id' => 'gen_cap2',
            'status' => 'succeeded',
            'usage' => [
                'input_text_tokens' => 1,
                'input_image_tokens' => 0,
                'output_tokens' => 280,
                'reasoning_tokens' => 360,
            ],
            'rates' => $this->rates,
        ];

        GenerationState::apply($generation, $object);
        GenerationState::apply($generation->fresh(), $object);

        $generation->refresh();
        $this->assertSame(19_631_250, $generation->charge_nano);
        $this->assertSame(0, $generation->reserved_nano);
        $this->assertSame(2_193_750, $user->fresh()->balance_nano, 'unused hold refunded exactly once');
    }

    public function test_submit_is_rejected_when_the_price_moved_since_the_displayed_estimate(): void
    {
        Http::fake(['*/pricing' => Http::response($this->pricing)]);

        $user = User::factory()->create(['balance_nano' => 10_000_000_000]);

        $this->actingAs($user)->post('/generate', [
            'prompt' => 'a lighthouse',
            'size' => '1024x1024',
            'quality' => 'medium',
            'displayed_charge_high' => '0.01', // stale: current high is 0.086275
        ])->assertSessionHasErrors('balance');

        $this->assertDatabaseCount('generations', 0);
        $this->assertSame(10_000_000_000, $user->fresh()->balance_nano);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/generations'));
    }

    public function test_submit_passes_when_the_displayed_estimate_matches(): void
    {
        Http::fake([
            '*/generations' => Http::response([
                'id' => 'gen_guard', 'status' => 'queued', 'rates' => $this->rates,
            ], 202),
            '*/pricing' => Http::response($this->pricing),
        ]);

        $user = User::factory()->create(['balance_nano' => 10_000_000_000]);

        $this->actingAs($user)->post('/generate', [
            'prompt' => 'a lighthouse',
            'size' => '1024x1024',
            'quality' => 'medium',
            'displayed_charge_high' => '0.086275',
        ])->assertRedirect(route('generate'));

        $this->assertDatabaseHas('generations', ['pixelforge_id' => 'gen_guard', 'reserved_nano' => 86_275_000]);
    }
}
