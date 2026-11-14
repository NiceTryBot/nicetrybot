<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: what a honeypot did with a trapped request, beside the request (one row at
     * most), gone with it when requests are pruned. created_at is when the line was imported.
     */
    public function up(): void
    {
        Schema::create('honeypot_hits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('edge_request_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('honeypot_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('honeypot_trap_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('outcome');
            $table->unsignedSmallInteger('status');
            $table->unsignedInteger('took_ms');
            $table->timestamp('created_at')->nullable();

            $table->index(['honeypot_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('honeypot_hits');
    }
};
