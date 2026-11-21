<?php

use App\Http\Controllers\AdminReportController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\PixelforgeWebhookController;
use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', fn () => redirect()->route('generate'))->name('dashboard');

Route::post('/webhooks/pixelforge', PixelforgeWebhookController::class)->name('webhooks.pixelforge');

Route::middleware('auth')->group(function () {
    Route::get('/generate', [GenerationController::class, 'index'])->name('generate');
    Route::post('/generate', [GenerationController::class, 'store'])
        ->middleware('throttle:'.max(1, (int) config('demo.generations_per_minute')).',1')
        ->name('generate.store');
    Route::get('/generate/estimate', [GenerationController::class, 'estimate'])->name('generate.estimate');
    Route::post('/credits', [CreditController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('credits.store');
    Route::get('/admin', [AdminReportController::class, 'index'])->name('admin.report');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
