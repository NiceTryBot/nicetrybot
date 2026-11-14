<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: a tarpit that's a trap, beside it (one row at most). A trap holds the very
     * requests that 404 or that contain one of its strings, each a strike that waits longer than the last,
     * stepping back a strike after cooldown_factor times the wait of quiet. Tarpits without a row take the
     * clients their rules send.
     */
    public function up(): void
    {
        Schema::create('tarpit_traps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tarpit_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('catches');
            $table->json('strings')->nullable();
            $table->unsignedTinyInteger('cooldown_factor')->default(5);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tarpit_traps');
    }
};
