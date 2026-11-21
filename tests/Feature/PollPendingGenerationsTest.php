<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PollPendingGenerationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_poll_recovers_a_job_when_no_webhook_was_delivered(): void
    {
        Storage::fake(config('filesystems.generations'));
        config(['services.pixelforge.api_key' => 'test-key']);

        $user = User::factory()->create(['balance_nano' => 10_000_000_000]);
        $generation = Generation::create([
            'user_id' => $user->id,
            'pixelforge_id' => 'gen_poll_1',
            'idempotency_key' => 'key-poll-1',
            'prompt' => 'a lighthouse',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'status' => 'processing',
            'reserved_nano' => 1_000_000_000,
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'updated_at' => now()->subMinutes(2),
        ]);

        // Force the stale updated_at past Eloquent timestamps.
        Generation::whereKey($generation->id)->update(['updated_at' => now()->subMinutes(2)]);

        Http::fake([
            '*/generations/gen_poll_1' => Http::response([
                'id' => 'gen_poll_1',
                'status' => 'succeeded',
                'usage' => [
                    'input_text_tokens' => 10,
                    'input_image_tokens' => 0,
                    'output_tokens' => 1100,
                    'reasoning_tokens' => 200,
                ],
                'rates' => [
                    'input_text_tokens' => '5.00',
                    'input_image_tokens' => '10.00',
                    'output_tokens' => '40.00',
                    'reasoning_tokens' => '12.50',
                ],
                'image_url' => 'https://pixelforge.promptedit.dev/images/gen_poll_1.png?signature=x',
            ]),
            '*/images/*' => Http::response('png-bytes'),
        ]);

        $this->assertDatabaseCount('webhook_events', 0);

        Artisan::call('pixelforge:poll');

        $generation->refresh();
        $this->assertSame('succeeded', $generation->status);
        $this->assertSame(46_550_000, $generation->provider_cost_nano);
        $this->assertSame('generations/gen_poll_1.png', $generation->image_path);
        $this->assertDatabaseCount('webhook_events', 0);
    }
}
