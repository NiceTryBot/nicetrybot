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
        Schema::create('gym_routine_gym_session', function (Blueprint $table) {
            $table->foreignId('gym_routine_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gym_session_id')->constrained()->cascadeOnDelete();

            $table->primary(['gym_routine_id', 'gym_session_id']);
            $table->index('gym_session_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gym_routine_gym_session');
    }
};
