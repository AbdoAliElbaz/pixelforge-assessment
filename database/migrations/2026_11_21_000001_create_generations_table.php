<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pixelforge_id')->nullable()->unique();
            $table->string('idempotency_key')->unique();
            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');
            // pending_submit: created locally, not yet accepted by Pixelforge
            // queued/processing/succeeded/failed: Pixelforge lifecycle
            // rejected: Pixelforge refused the request (nothing created or billed)
            // submit_failed: we could not get the request accepted after retries
            $table->string('status')->default('pending_submit');
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->json('rates')->nullable();   // provider rates snapshot at creation (USD per 1M tokens)
            $table->json('usage')->nullable();   // token usage, set once succeeded
            $table->unsignedBigInteger('provider_cost_nano')->nullable(); // what Pixelforge charges us, in nanodollars
            $table->unsignedBigInteger('charge_nano')->nullable();        // what we charge the user (cost + 25%)
            $table->string('image_path')->nullable(); // local copy; provider URLs expire after 24h
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
