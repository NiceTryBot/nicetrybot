<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: what a tarpit did to a request, beside the request (one row at most), gone with
     * it when requests are pruned. created_at is when the line was imported, for the tarpit's own totals.
     */
    public function up(): void
    {
        Schema::create('tarpit_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('edge_request_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('tarpit_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('reason');
            $table->unsignedTinyInteger('outcome');
            $table->unsignedSmallInteger('try');
            $table->unsignedInteger('held_ms');
            $table->timestamp('created_at')->nullable();

            $table->index(['tarpit_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tarpit_holds');
    }
};
