<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * These roll a day's gym sessions up once, so the gym page reads a handful of summary rows
     * instead of scanning the whole gym_sessions table on every load.
     */
    public function up(): void
    {
        Schema::create('gym_team_daily_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('entered')->default(0);
            $table->unsignedInteger('cleared')->default(0);
            $table->unsignedInteger('turned_away')->default(0);
            $table->unsignedInteger('gave_up')->default(0);
            $table->unsignedBigInteger('hashes')->default(0);
            $table->timestamps();

            $table->unique(['team_id', 'day']);
        });

        Schema::create('gym_routine_daily_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gym_routine_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('entered')->default(0);
            $table->unsignedInteger('cleared')->default(0);
            $table->timestamps();

            $table->unique(['gym_routine_id', 'day']);
            $table->index(['team_id', 'day']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gym_routine_daily_metrics');
        Schema::dropIfExists('gym_team_daily_metrics');
    }
};
