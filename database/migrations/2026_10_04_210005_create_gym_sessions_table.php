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
        Schema::create('gym_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('domain_id')->nullable()->constrained()->nullOnDelete();
            $table->string('token', 64)->unique();
            $table->boolean('is_preview')->default(false);
            $table->unsignedTinyInteger('status');
            $table->unsignedTinyInteger('exit_action')->nullable();
            $table->string('destination', 2048)->nullable();
            $table->ipAddress('client_ip')->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('ja4', 64)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->unsignedInteger('asn')->nullable();
            $table->json('progress');
            $table->json('escalations');
            $table->json('draft')->nullable();
            $table->unsignedBigInteger('hashes')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'is_preview', 'started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gym_sessions');
    }
};
