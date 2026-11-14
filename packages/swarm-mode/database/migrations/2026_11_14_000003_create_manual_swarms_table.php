<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: Swarm Mode turned on by hand, for a site or one path on it, until it ends or
     * someone ends it early.
     */
    public function up(): void
    {
        Schema::create('manual_swarms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('path_id')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ends_at');
            $table->timestamp('ended_at')->nullable();

            $table->index(['team_id', 'ends_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('manual_swarms');
    }
};
