<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'whsec_testsecret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.pixelforge.webhook_secret' => $this->secret]);
        Storage::fake(config('filesystems.generations'));
        Http::fake(['*/images/*' => Http::response('png-bytes')]);
    }

    private function generation(array $attributes = []): Generation
    {
        return Generation::create(array_merge([
            'user_id' => User::factory()->create(['balance_nano' => 10_000_000_000])->id,
            'pixelforge_id' => 'gen_test123',
            'idempotency_key' => 'key-'.uniqid(),
            'prompt' => 'a lighthouse',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'status' => 'processing',
            'reserved_nano' => 1_000_000_000, // hold taken at submit
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
        ], $attributes));
    }

    private function sendEvent(array $event, ?string $secret = null)
    {
        $payload = json_encode($event);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret ?? $this->secret);

        return $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'HTTP_Pixelforge-Signature' => "t={$timestamp},v1={$signature}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }

    private function succeededEvent(string $eventId, string $generationId, array $usage): array
    {
        return [
            'id' => $eventId,
            'object' => 'event',
            'type' => 'generation.succeeded',
            'data' => ['object' => [
                'id' => $generationId,
                'status' => 'succeeded',
                'usage' => $usage,
                'rates' => [
                    'input_text_tokens' => '5.00',
                    'input_image_tokens' => '10.00',
                    'output_tokens' => '40.00',
                    'reasoning_tokens' => '12.50',
                ],
                'image_url' => 'https://pixelforge.promptedit.dev/images/gen_test123.png?signature=x',
            ]],
        ];
    }

    public function test_rejects_an_invalid_signature(): void
    {
        $this->sendEvent(['id' => 'evt_1', 'type' => 'ping', 'data' => []], 'wrong-secret')
            ->assertStatus(400);
    }

    public function test_rejects_when_webhook_secret_is_not_configured(): void
    {
        config(['services.pixelforge.webhook_secret' => '']);

        $this->sendEvent(['id' => 'evt_missing_secret', 'type' => 'ping', 'data' => []], '')
            ->assertStatus(503);
    }

    public function test_rejects_events_without_an_id(): void
    {
        $this->sendEvent(['type' => 'ping', 'data' => []])
            ->assertStatus(400);

        $this->sendEvent(['id' => '', 'type' => 'ping', 'data' => []])
            ->assertStatus(400);

        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_succeeded_charges_exactly_once(): void
    {
        $generation = $this->generation();
        $usage = [
            'input_text_tokens' => 10,
            'input_image_tokens' => 0,
            'output_tokens' => 1100,
            'reasoning_tokens' => 200,
            'total_tokens' => 1310,
        ];

        $this->sendEvent($this->succeededEvent('evt_1', $generation->pixelforge_id, $usage))->assertNoContent();
        // The exact same event delivered again (duplicate delivery) must not re-apply.
        $this->sendEvent($this->succeededEvent('evt_1', $generation->pixelforge_id, $usage))->assertNoContent();

        $generation->refresh();
        // cost = 10*5000 + 1100*40000 + 200*12500 = 46,550,000 nano; charge = +25%
        $this->assertSame('succeeded', $generation->status);
        $this->assertSame(46_550_000, $generation->provider_cost_nano);
        $this->assertSame(58_187_500, $generation->charge_nano);
        $this->assertSame('generations/gen_test123.png', $generation->image_path);
    }

    public function test_failed_generation_is_never_charged(): void
    {
        $generation = $this->generation();

        $this->sendEvent([
            'id' => 'evt_2',
            'type' => 'generation.failed',
            'data' => ['object' => [
                'id' => $generation->pixelforge_id,
                'status' => 'failed',
                'error' => ['code' => 'generation_failed', 'message' => 'Could not generate.'],
            ]],
        ])->assertNoContent();

        $generation->refresh();
        $this->assertSame('failed', $generation->status);
        $this->assertNull($generation->provider_cost_nano);
        $this->assertNull($generation->charge_nano);
    }

    public function test_out_of_order_processing_event_never_moves_a_finished_generation_back(): void
    {
        $generation = $this->generation();
        $usage = ['input_text_tokens' => 1, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 200];

        $this->sendEvent($this->succeededEvent('evt_3', $generation->pixelforge_id, $usage))->assertNoContent();
        $this->sendEvent([
            'id' => 'evt_4',
            'type' => 'generation.processing',
            'data' => ['object' => ['id' => $generation->pixelforge_id, 'status' => 'processing']],
        ])->assertNoContent();

        $this->assertSame('succeeded', $generation->refresh()->status);
    }

    public function test_ping_is_acknowledged_and_dropped(): void
    {
        $this->sendEvent(['id' => 'evt_5', 'type' => 'ping', 'data' => ['object' => []]])->assertNoContent();
        $this->assertDatabaseHas('webhook_events', ['event_id' => 'evt_5']);
    }
}
