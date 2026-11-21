<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            // Funds held at submit time (the high end of the cost estimate).
            // Settled to the exact charge on success, fully refunded on failure.
            $table->unsignedBigInteger('reserved_nano')->default(0)->after('charge_nano');
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropColumn('reserved_nano');
        });
    }
};
