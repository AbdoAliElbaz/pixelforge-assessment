<?php

namespace Tests\Feature;

use App\Jobs\DownloadGenerationImage;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class DownloadGenerationImageTest extends TestCase
{
    use RefreshDatabase;

    private function generation(): Generation
    {
        return Generation::create([
            'user_id' => User::factory()->create()->id,
            'pixelforge_id' => 'gen_dl_1',
            'idempotency_key' => 'key-dl-1',
            'prompt' => 'test',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'status' => 'succeeded',
        ]);
    }

    public function test_retries_when_image_download_fails(): void
    {
        Storage::fake(config('filesystems.generations'));
        Http::fake([
            'https://pixelforge.promptedit.dev/*' => Http::response('nope', 500),
        ]);

        $generation = $this->generation();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Pixelforge image download failed; will retry.');

        (new DownloadGenerationImage(
            $generation->id,
            'https://pixelforge.promptedit.dev/images/gen_dl_1.png?signature=x',
        ))->handle(app(\App\Services\PixelforgeClient::class));

        $this->assertNull($generation->refresh()->image_path);
    }

    public function test_refuses_untrusted_image_hosts(): void
    {
        Storage::fake(config('filesystems.generations'));
        Http::fake();

        $generation = $this->generation();
        $job = new DownloadGenerationImage($generation->id, 'http://127.0.0.1/evil.png');

        // InteractsWithQueue::fail needs a fake job instance in unit-style calls.
        $job->withFakeQueueInteractions();
        $job->handle(app(\App\Services\PixelforgeClient::class));
        $job->assertFailedWith(RuntimeException::class);

        Http::assertNothingSent();
        $this->assertNull($generation->refresh()->image_path);
    }

    public function test_stores_image_from_trusted_host(): void
    {
        Storage::fake(config('filesystems.generations'));
        Http::fake([
            'https://pixelforge.promptedit.dev/*' => Http::response('png-bytes'),
        ]);

        $generation = $this->generation();

        (new DownloadGenerationImage(
            $generation->id,
            'https://pixelforge.promptedit.dev/images/gen_dl_1.png?signature=x',
        ))->handle(app(\App\Services\PixelforgeClient::class));

        $this->assertSame('generations/gen_dl_1.png', $generation->refresh()->image_path);
        Storage::disk(config('filesystems.generations'))->assertExists('generations/gen_dl_1.png');
    }
}
