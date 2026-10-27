<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('gym_routines', function (Blueprint $table) {
            $table->boolean('one_try')->default(false);
        });

        Schema::table('gym_sessions', function (Blueprint $table) {
            $table->string('pass_state', 16)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gym_routines', function (Blueprint $table) {
            $table->dropColumn('one_try');
        });

        Schema::table('gym_sessions', function (Blueprint $table) {
            $table->dropColumn('pass_state');
        });
    }
};
