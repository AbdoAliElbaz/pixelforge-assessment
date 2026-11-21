<?php

namespace Database\Seeders;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Local demo data only: two throwaway accounts (password "password123")
     * and a few generations so the generate page and admin report show
     * something on first run. Never use these in a real deployment.
     */
    public function run(): void
    {
        $demo = User::factory()->create([
            'name' => 'Demo User',
            'email' => 'demo@example.com',
            'password' => 'password123',
            'balance_nano' => 5_000_000_000,
        ]);

        User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => 'password123',
            'is_admin' => true,
            'balance_nano' => 5_000_000_000,
        ]);

        $rates = [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ];

        // The docs' worked example: maximum-cost high 1536x1024 with 2 reference
        // images: cost $0.373875, charge $0.46734375. Seeded as completed today.
        $usage = [
            'input_text_tokens' => 115,
            'input_image_tokens' => 1530,
            'output_tokens' => 6450,
            'reasoning_tokens' => 8000,
        ];

        $demo->generations()->create([
            'pixelforge_id' => 'gen_demo_succeeded',
            'idempotency_key' => 'demo-succeeded',
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png'],
            'status' => 'succeeded',
            'rates' => $rates,
            'usage' => $usage,
            'provider_cost_nano' => 373_875_000,
            'charge_nano' => 467_343_750,
            'completed_at' => now(),
            'created_at' => now()->subMinutes(10),
        ]);
        $demo->decrement('balance_nano', 467_343_750);

        $demo->generations()->create([
            'pixelforge_id' => 'gen_demo_yesterday',
            'idempotency_key' => 'demo-yesterday',
            'prompt' => 'Minimalist poster of a desert dune',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'status' => 'succeeded',
            'rates' => $rates,
            'usage' => [
                'input_text_tokens' => 10,
                'input_image_tokens' => 0,
                'output_tokens' => 1100,
                'reasoning_tokens' => 500,
            ],
            'provider_cost_nano' => 50_300_000,
            'charge_nano' => 62_875_000,
            'completed_at' => now()->subDay(),
            'created_at' => now()->subDay(),
        ]);
        $demo->decrement('balance_nano', 62_875_000);

        $demo->generations()->create([
            'pixelforge_id' => 'gen_demo_failed',
            'idempotency_key' => 'demo-failed',
            'prompt' => 'Cyberpunk city in the rain [fail]',
            'size' => '1024x1536',
            'quality' => 'low',
            'reference_images' => [],
            'status' => 'failed',
            'error_code' => 'generation_failed',
            'error_message' => 'The model could not produce an image for the prompt.',
            'rates' => $rates,
            'completed_at' => now()->subHour(),
            'created_at' => now()->subHour(),
        ]);
    }
}
