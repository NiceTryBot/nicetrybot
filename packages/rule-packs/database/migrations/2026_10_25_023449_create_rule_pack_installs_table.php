<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: which of a team's rules and gym routines came from a rule pack, one row per
     * pack item, with the pack's version it was last installed at. The fingerprint is what the item
     * looked like when the pack wrote it, so an item the team has since edited can be told apart. A row
     * goes with its rule or routine when that's deleted.
     */
    public function up(): void
    {
        Schema::create('rule_pack_installs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('pack', 64);
            $table->string('item', 64);
            $table->unsignedInteger('version');
            $table->foreignId('edge_rule_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('gym_routine_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('fingerprint', 32);
            $table->timestamps();

            $table->unique(['team_id', 'pack', 'item']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rule_pack_installs');
    }
};
