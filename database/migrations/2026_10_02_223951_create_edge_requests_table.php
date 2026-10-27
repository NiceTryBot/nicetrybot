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
        Schema::create('edge_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->timestamp('occurred_at');
            $table->string('client_ip', 45);
            $table->string('country_code', 2)->nullable();
            $table->string('method', 10);
            $table->string('path', 2048);
            $table->unsignedSmallInteger('status_code');
            $table->unsignedInteger('duration_ms');
            $table->string('user_agent', 512)->nullable();
            $table->string('action')->default('passed');
            $table->string('bot_tag')->nullable();

            $table->index(['domain_id', 'occurred_at']);
            $table->index(['domain_id', 'status_code', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('edge_requests');
    }
};
