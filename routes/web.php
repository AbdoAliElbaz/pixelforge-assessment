<?php

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

Route::post('/webhooks/pixelforge', \App\Http\Controllers\PixelforgeWebhookController::class)->name('webhooks.pixelforge');

Route::middleware('auth')->group(function () {
    Route::get('/generate', [\App\Http\Controllers\GenerationController::class, 'index'])->name('generate');
    Route::post('/generate', [\App\Http\Controllers\GenerationController::class, 'store'])->name('generate.store');
    Route::get('/generate/estimate', [\App\Http\Controllers\GenerationController::class, 'estimate'])->name('generate.estimate');
    Route::post('/credits', [\App\Http\Controllers\CreditController::class, 'store'])->name('credits.store');
    Route::get('/admin', [\App\Http\Controllers\AdminReportController::class, 'index'])->name('admin.report');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
