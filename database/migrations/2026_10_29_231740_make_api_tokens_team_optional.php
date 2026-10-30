<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A token without a team works on all of its user's teams.
     */
    public function up(): void
    {
        Schema::table('api_tokens', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations: tokens for all teams can't be kept.
     */
    public function down(): void
    {
        DB::table('api_tokens')->whereNull('team_id')->delete();

        Schema::table('api_tokens', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable(false)->change();
        });
    }
};
