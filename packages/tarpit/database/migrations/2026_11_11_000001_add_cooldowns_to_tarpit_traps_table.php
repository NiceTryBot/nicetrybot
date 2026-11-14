<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Give a trap an explicit cooldown ladder: the seconds of quiet needed to step back from each strike,
     * in turn (10, 20, 30, 60, 120 by default), in place of the single cooldown_factor it multiplied the
     * wait by. The old column stays (it has a default), so this is additive; the model reads cooldowns.
     */
    public function up(): void
    {
        Schema::table('tarpit_traps', function (Blueprint $table) {
            $table->json('cooldowns')->nullable()->after('strings');
        });

        DB::table('tarpit_traps')->whereNull('cooldowns')->update([
            'cooldowns' => json_encode([10, 20, 30, 60, 120]),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tarpit_traps', function (Blueprint $table) {
            $table->dropColumn('cooldowns');
        });
    }
};
