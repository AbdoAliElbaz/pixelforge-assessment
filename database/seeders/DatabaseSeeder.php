<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demo accounts (password "password123") + sample generations.
 * Does not use factories/Faker so `migrate --seed` works with --no-dev on Cloud.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $demo = User::query()->updateOrCreate(
            ['email' => 'demo@example.com'],
            [
                'name' => 'Demo User',
                'password' => 'password123',
                'balance_nano' => 5_000_000_000,
                'is_admin' => false,
            ],
        );

        User::query()->updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin User',
                'password' => 'password123',
                'balance_nano' => 5_000_000_000,
                'is_admin' => true,
            ],
        );

        if ($demo->generations()->exists()) {
            return;
        }

        $rates = [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ];

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
            'is_fixture' => true,
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
            'is_fixture' => true,
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
            'is_fixture' => true,
            'completed_at' => now()->subHour(),
            'created_at' => now()->subHour(),
        ]);
    }
}
