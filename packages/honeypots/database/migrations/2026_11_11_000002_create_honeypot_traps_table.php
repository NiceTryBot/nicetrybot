<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: a team's honeypot traps, each the path patterns whose 404s go to one of its
     * honeypots, on every domain of the team (no domain) or on one.
     */
    public function up(): void
    {
        Schema::create('honeypot_traps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('honeypot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('domain_id')->nullable()->constrained()->cascadeOnDelete();
            $table->json('patterns');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['team_id', 'enabled']);
            $table->index('honeypot_id');
            $table->index('domain_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('honeypot_traps');
    }
};
