<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whitelist_entries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // A category, written X/Y: bot/crawler, bot/ai, office/vpn, and so on.
            $table->string('category')->index();
            // The entry's IPs and CIDR ranges, normalised, as a JSON list.
            $table->json('ranges');
            $table->boolean('enabled')->default(true)->index();
            $table->string('reason')->nullable();
            $table->foreignId('added_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whitelist_entries');
    }
};
