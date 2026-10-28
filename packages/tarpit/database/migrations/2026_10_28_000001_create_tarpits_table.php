<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: a team's tarpits, how each makes its clients wait, and who goes in. Rules put
     * their verdicts' clients in (tarpit_rules); a tarpit covers every domain of the team in Enforce mode
     * unless it names some (tarpit_domains).
     */
    public function up(): void
    {
        Schema::create('tarpits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->boolean('enabled')->default(true);
            $table->boolean('all_domains')->default(true);
            $table->unsignedTinyInteger('free_tries')->default(1);
            $table->json('delays');
            $table->unsignedSmallInteger('max_delay_seconds')->default(30);
            $table->unsignedSmallInteger('max_held')->default(100);
            $table->unsignedTinyInteger('when_full');
            $table->unsignedTinyInteger('after_wait');
            $table->unsignedInteger('forget_after_seconds')->default(900);
            $table->boolean('takes_gym_no_shows')->default(false);
            $table->unsignedSmallInteger('not_found_count')->nullable();
            $table->unsignedSmallInteger('not_found_within_seconds')->default(60);
            $table->unsignedSmallInteger('burst_count')->nullable();
            $table->unsignedSmallInteger('burst_within_seconds')->default(10);
            $table->timestamps();

            $table->index(['team_id', 'enabled']);
        });

        Schema::create('tarpit_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tarpit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('edge_rule_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['tarpit_id', 'edge_rule_id']);
            $table->index('edge_rule_id');
        });

        Schema::create('tarpit_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tarpit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['tarpit_id', 'domain_id']);
            $table->index('domain_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tarpit_domains');
        Schema::dropIfExists('tarpit_rules');
        Schema::dropIfExists('tarpits');
    }
};
