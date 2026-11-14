<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: the rules whose SWARM clause turns Swarm Mode on, beside them (one row at most
     * per rule), gone with the rule.
     */
    public function up(): void
    {
        Schema::create('swarm_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('edge_rule_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('swarm_rules');
    }
};
