<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminReportTest extends TestCase
{
    use RefreshDatabase;

    private array $rates = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    private function succeededGeneration(User $user, string $completedAt, int $costNano, int $chargeNano): Generation
    {
        return Generation::create([
            'user_id' => $user->id,
            'pixelforge_id' => 'gen_'.uniqid(),
            'idempotency_key' => 'key-'.uniqid(),
            'prompt' => 'a lighthouse',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'status' => 'succeeded',
            'rates' => $this->rates,
            'usage' => ['input_text_tokens' => 1, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 200],
            'provider_cost_nano' => $costNano,
            'charge_nano' => $chargeNano,
            'completed_at' => $completedAt,
        ]);
    }

    public function test_non_admin_users_cannot_open_the_report(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/login');
    }

    public function test_report_groups_spend_cost_and_profit_per_day_and_user_and_reconciles(): void
    {
        config(['services.pixelforge.api_key' => 'test-key']);
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create(['email' => 'customer@example.com']);
        $today = now()->format('Y-m-d');

        // Provider cost $0.373875, charge $0.46734375 (the docs' worked example).
        $this->succeededGeneration($user, now()->toDateTimeString(), 373_875_000, 467_343_750);
        // A failed generation must not appear in money totals.
        Generation::create([
            'user_id' => $user->id,
            'pixelforge_id' => 'gen_failed_'.uniqid(),
            'idempotency_key' => 'key-f-'.uniqid(),
            'prompt' => 'nope',
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_images' => [],
            'status' => 'failed',
            'rates' => $this->rates,
            'completed_at' => now()->toDateTimeString(),
        ]);

        Http::fake(['*/usage*' => Http::response([
            'object' => 'usage',
            'data' => [
                ['date' => $today, 'generations' => 1, 'input_text_tokens' => 1, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 200, 'cost_usd' => '0.373875000'],
            ],
        ])]);

        $this->actingAs($admin)->get('/admin')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Report')
            ->has('rows', 1)
            ->where('rows.0.day', $today)
            ->where('rows.0.user', 'customer@example.com')
            ->where('rows.0.charged', '0.46734375')
            ->where('rows.0.cost', '0.373875')
            ->where('rows.0.profit', '0.09346875')
            ->where('reconciliation.0.pixelforge_cost', '0.373875')
            ->where('reconciliation.0.our_cost', '0.373875')
            ->where('reconciliation.0.matches', true)
        );
    }

    public function test_reconciliation_flags_a_mismatch(): void
    {
        config(['services.pixelforge.api_key' => 'test-key']);
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();
        $today = now()->format('Y-m-d');

        $this->succeededGeneration($user, now()->toDateTimeString(), 373_875_000, 467_343_750);

        Http::fake(['*/usage*' => Http::response([
            'object' => 'usage',
            'data' => [
                ['date' => $today, 'generations' => 2, 'input_text_tokens' => 2, 'input_image_tokens' => 0, 'output_tokens' => 2200, 'reasoning_tokens' => 400, 'cost_usd' => '0.747750000'],
            ],
        ])]);

        $this->actingAs($admin)->get('/admin')->assertInertia(fn (Assert $page) => $page
            ->where('reconciliation.0.matches', false)
        );
    }

    public function test_report_works_without_an_api_key_using_only_our_records(): void
    {
        config(['services.pixelforge.api_key' => null]);
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();

        $this->succeededGeneration($user, now()->toDateTimeString(), 373_875_000, 467_343_750);

        Http::preventStrayRequests();

        $this->actingAs($admin)->get('/admin')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('rows', 1)
            ->where('reconciliation', null)
        );
    }

    public function test_seeded_fixtures_are_excluded_from_spend_and_reconciliation(): void
    {
        config(['services.pixelforge.api_key' => 'test-key']);
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create(['email' => 'customer@example.com']);
        $today = now()->format('Y-m-d');

        $real = $this->succeededGeneration($user, now()->toDateTimeString(), 50_412_500, 63_015_625);
        $fixture = $this->succeededGeneration($user, now()->toDateTimeString(), 373_875_000, 467_343_750);
        $fixture->forceFill(['is_fixture' => true, 'pixelforge_id' => 'gen_demo_succeeded'])->save();

        Http::fake(['*/usage*' => Http::response([
            'object' => 'usage',
            'data' => [
                ['date' => $today, 'generations' => 1, 'input_text_tokens' => 10, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 509, 'cost_usd' => '0.050412500'],
            ],
        ])]);

        $this->actingAs($admin)->get('/admin')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('rows', 1)
            ->where('rows.0.user', 'customer@example.com')
            ->where('rows.0.generations', 1)
            ->where('rows.0.cost', '0.0504125')
            ->where('reconciliation.0.our_generations', 1)
            ->where('reconciliation.0.our_cost', '0.0504125')
            ->where('reconciliation.0.matches', true)
        );

        $this->assertDatabaseHas('generations', ['id' => $real->id, 'is_fixture' => false]);
        $this->assertDatabaseHas('generations', ['id' => $fixture->id, 'is_fixture' => true]);
    }
}
