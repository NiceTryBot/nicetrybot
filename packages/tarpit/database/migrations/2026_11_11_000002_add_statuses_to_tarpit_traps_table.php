<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let a status trap (the old 404 trap, catches = 1) hold a chosen set of response statuses, not just
     * the 404: the seconds a request's answer waits when its status is one listed here (403, 500…).
     * Additive; existing status traps keep the 404 they already caught.
     */
    public function up(): void
    {
        Schema::table('tarpit_traps', function (Blueprint $table) {
            $table->json('statuses')->nullable()->after('strings');
        });

        DB::table('tarpit_traps')
            ->where('catches', 1)
            ->whereNull('statuses')
            ->update(['statuses' => json_encode([404])]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tarpit_traps', function (Blueprint $table) {
            $table->dropColumn('statuses');
        });
    }
};
