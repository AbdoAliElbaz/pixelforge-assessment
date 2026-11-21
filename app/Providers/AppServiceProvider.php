<?php

namespace App\Providers;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
        $this->ensurePublicStorageLink();
    }

    /**
     * Local public-disk images need public/storage -> storage/app/public.
     * Create the link when missing (shared FS). Skip in tests and when the
     * generations disk is object storage (no local symlink required).
     */
    private function ensurePublicStorageLink(): void
    {
        if ($this->app->runningUnitTests()) {
            return;
        }

        $disk = config('filesystems.generations');
        if (config("filesystems.disks.{$disk}.driver") !== 'local') {
            return;
        }

        if (file_exists(public_path('storage'))) {
            return;
        }

        try {
            Artisan::call('storage:link');
        } catch (Throwable) {
            // Deployments without symlink permission should set GENERATIONS_DISK to object storage.
        }
    }
}
