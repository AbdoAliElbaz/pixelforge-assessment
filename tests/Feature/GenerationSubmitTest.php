<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GenerationSubmitTest extends TestCase
{
    use RefreshDatabase;

    private array $rates = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    /** Matches GET /v1/pricing; submit now estimates cost for the balance check. */
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

    private function fundedUser(): User
    {
        return User::factory()->create(['balance_nano' => 10_000_000_000]);
    }

    public function test_successful_submit_stores_the_remote_id_and_rates(): void
    {
        Http::fake([
            '*/generations' => Http::response([
                'id' => 'gen_abc', 'status' => 'queued', 'rates' => $this->rates,
            ], 202),
            '*/pricing' => Http::response($this->pricing),
        ]);

        $this->actingAs($this->fundedUser())->post('/generate', [
            'prompt' => 'a lighthouse',
            'size' => '1024x1024',
            'quality' => 'medium',
        ])->assertRedirect(route('generate'));

        $this->assertDatabaseHas('generations', [
            'pixelforge_id' => 'gen_abc',
            'status' => 'queued',
        ]);

        Http::assertSent(fn ($request) => $request->hasHeader('Idempotency-Key'));
    }

    public function test_content_policy_rejection_creates_nothing_and_charges_nothing(): void
    {
        Http::fake([
            '*/generations' => Http::response([
                'error' => ['code' => 'content_policy_violation', 'message' => 'The prompt was rejected.'],
            ], 400),
            '*/pricing' => Http::response($this->pricing),
        ]);

        $this->actingAs($this->fundedUser())->post('/generate', [
            'prompt' => '[blocked] something',
            'size' => '1024x1024',
            'quality' => 'medium',
        ]);

        $this->assertDatabaseHas('generations', [
            'status' => 'rejected',
            'provider_cost_nano' => null,
            'charge_nano' => null,
        ]);
    }

    public function test_overload_retries_with_the_same_idempotency_key_then_gives_up_free(): void
    {
        Http::fake([
            '*/generations' => Http::response([
                'error' => ['code' => 'overloaded', 'message' => 'Overloaded.'],
            ], 503, ['Retry-After' => '1']),
            '*/pricing' => Http::response($this->pricing),
        ]);

        $this->actingAs($this->fundedUser())->post('/generate', [
            'prompt' => '[overloaded] retry me',
            'size' => '1024x1024',
            'quality' => 'low',
        ]);

        $this->assertDatabaseHas('generations', ['status' => 'submit_failed', 'charge_nano' => null]);

        $keys = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), '/generations'))
            ->map(fn ($pair) => $pair[0]->header('Idempotency-Key')[0] ?? null);
        $this->assertGreaterThan(1, $keys->count());
        $this->assertSame(1, $keys->unique()->count(), 'every retry must reuse one idempotency key');
    }
}
