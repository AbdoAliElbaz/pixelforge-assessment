<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Credit balance in nanodollars (1 USD = 1,000,000,000 nano). Never a float.
            $table->unsignedBigInteger('balance_nano')->default(0)->after('password');
            $table->boolean('is_admin')->default(false)->after('balance_nano');
        });

        Schema::table('generations', function (Blueprint $table) {
            // Pixelforge bills on the UTC day a generation completes; we keep the
            // same completion day so the admin report reconciles with GET /v1/usage.
            $table->timestamp('completed_at')->nullable()->after('image_path');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['balance_nano', 'is_admin']);
        });

        Schema::table('generations', function (Blueprint $table) {
            $table->dropColumn('completed_at');
        });
    }
};
