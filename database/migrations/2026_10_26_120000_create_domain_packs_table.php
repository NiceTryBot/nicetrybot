<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: the More domains packs this installation holds, one row per key from the
     * marketplace. Each valid pack adds 100 domains to the free 10; packs stack.
     */
    public function up(): void
    {
        Schema::create('domain_packs', function (Blueprint $table) {
            $table->id();
            $table->string('licence_id', 32)->unique();
            $table->text('key');
            $table->string('licensed_to')->nullable();
            $table->date('issued_on')->nullable();
            $table->string('status', 16)->default('valid');
            $table->timestamp('checked_at')->nullable();
            $table->foreignId('entered_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('domain_packs');
    }
};
