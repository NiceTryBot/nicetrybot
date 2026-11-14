<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: verdicts on a scope rather than a client, from rules over host and path (Swarm
     * Mode, say): a site (domain_id), a path on every site (path_id), or one path on one site (both).
     * Kept apart from client verdicts, where an IP and fingerprint both left empty mean "every client".
     */
    public function up(): void
    {
        Schema::create('scope_verdicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('edge_rule_id')->constrained()->cascadeOnDelete();
            $table->string('tag', 64)->nullable();
            $table->foreignId('domain_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('path_id')->nullable();
            $table->unsignedInteger('matched_requests')->default(0);
            $table->timestamp('first_matched_at');
            $table->timestamp('last_matched_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('lifted_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['team_id', 'expires_at']);
            $table->index(['edge_rule_id', 'domain_id', 'path_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scope_verdicts');
    }
};
