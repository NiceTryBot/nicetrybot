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
        Schema::create('gym_routines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->boolean('match_all')->default(true);
            $table->json('conditions');
            $table->json('obstacles');
            $table->unsignedTinyInteger('exit_action');
            $table->unsignedInteger('pass_seconds');
            $table->timestamps();

            $table->index(['team_id', 'enabled', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gym_routines');
    }
};
