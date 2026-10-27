<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: a team's lockdown, the switch that puts its locked sites behind a maintenance
     * page at the edge. Engaged while engaged_at is set. The secret signs the passes that let the team's
     * admins through, so changing it revokes them all.
     */
    public function up(): void
    {
        Schema::create('lockdowns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamp('engaged_at')->nullable();
            $table->text('secret');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lockdowns');
    }
};
