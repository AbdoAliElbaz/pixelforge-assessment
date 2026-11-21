<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            // Snapshot of the user-facing estimate (cost + 25% markup) at submit.
            // Nullable so pre-existing / seeded rows can show "Estimate unavailable".
            $table->unsignedBigInteger('estimate_charge_low_nano')->nullable()->after('reserved_nano');
            $table->unsignedBigInteger('estimate_charge_high_nano')->nullable()->after('estimate_charge_low_nano');
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropColumn(['estimate_charge_low_nano', 'estimate_charge_high_nano']);
        });
    }
};
