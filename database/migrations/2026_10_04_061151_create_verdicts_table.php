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
        Schema::create('verdicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('edge_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('action');
            $table->string('tag', 64)->nullable();
            $table->ipAddress('client_ip')->nullable();
            $table->unsignedInteger('ja4_id')->nullable();
            $table->unsignedInteger('matched_requests')->default(0);
            $table->timestamp('first_matched_at');
            $table->timestamp('last_matched_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('lifted_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['team_id', 'expires_at']);
            $table->index(['edge_rule_id', 'client_ip', 'ja4_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('verdicts');
    }
};
