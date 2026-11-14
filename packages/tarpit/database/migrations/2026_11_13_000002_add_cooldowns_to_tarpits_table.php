<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: any tarpit may now step a client back one try for each stretch of quiet (the
     * cooldowns in turn), not only a trap.
     */
    public function up(): void
    {
        Schema::table('tarpits', function (Blueprint $table) {
            $table->json('cooldowns')->nullable()->after('forget_after_seconds');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tarpits', function (Blueprint $table) {
            $table->dropColumn('cooldowns');
        });
    }
};
