<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: a team's honeypots, each an origin of its own (a Galah server, say) that the
     * edge sends trapped 404s to, with the secret it proves itself with and how long it may take.
     */
    public function up(): void
    {
        Schema::create('honeypots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('origin', 2048);
            $table->string('secret', 64);
            $table->unsignedTinyInteger('timeout_seconds')->default(30);
            $table->timestamps();

            $table->index('team_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('honeypots');
    }
};
