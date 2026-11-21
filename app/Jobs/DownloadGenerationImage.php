<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\PixelforgeClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DownloadGenerationImage implements ShouldQueue
{
    use Queueable;

    /** Signed URLs live ~24h; retry transient fetch failures within that window. */
    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 60, 120];

    public function __construct(public int $generationId, public string $imageUrl) {}

    public function handle(PixelforgeClient $client): void
    {
        $generation = Generation::find($this->generationId);
        if ($generation === null || $generation->image_path !== null) {
            return;
        }

        if (! $this->isTrustedImageHost($this->imageUrl)) {
            $this->fail(new RuntimeException('Refusing to download image from untrusted host.'));

            return;
        }

        $png = $client->downloadImage($this->imageUrl);
        if ($png === null) {
            throw new RuntimeException('Pixelforge image download failed; will retry.');
        }

        $path = "generations/{$generation->pixelforge_id}.png";
        // Do not pass visibility 'public': R2/Cloud Object Storage rejects per-object ACLs;
        // bucket-level Public visibility already makes these URLs readable.
        Storage::disk(config('filesystems.generations'))->put($path, $png);
        $generation->forceFill(['image_path' => $path])->save();
    }

    /** Reject SSRF if a forged/compromised webhook pointed image_url elsewhere. */
    private function isTrustedImageHost(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        $baseHost = parse_url((string) config('services.pixelforge.base_url'), PHP_URL_HOST);

        $allowed = array_values(array_unique(array_filter([
            $baseHost !== null ? strtolower($baseHost) : null,
            'pixelforge.promptedit.dev',
        ])));

        return $host !== null && in_array(strtolower($host), $allowed, true);
    }
}
