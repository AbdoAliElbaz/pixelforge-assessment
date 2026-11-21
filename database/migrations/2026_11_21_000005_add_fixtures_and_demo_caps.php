<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            // Seeded demo rows must not pollute spend or Pixelforge reconciliation.
            $table->boolean('is_fixture')->default(false)->after('image_path');
            $table->index('is_fixture');
        });

        Schema::table('users', function (Blueprint $table) {
            // Lifetime demo top-ups (USD whole dollars) so free credits cannot burn the shared quota unboundedly.
            $table->unsignedInteger('credits_purchased_usd')->default(0)->after('balance_nano');
        });

        DB::table('generations')
            ->where('pixelforge_id', 'like', 'gen_demo_%')
            ->update(['is_fixture' => true]);
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropIndex(['is_fixture']);
            $table->dropColumn('is_fixture');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('credits_purchased_usd');
        });
    }
};
