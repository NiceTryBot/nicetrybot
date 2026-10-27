<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: the licence this installation holds for each paid add-on, by its slug, and
     * what the marketplace last said about it. A row is made the first time a paid add-on is seen
     * without a key (first_seen_at starts its trial) or when a key is entered. There's one
     * installation-wide licence per add-on, not one per team.
     */
    public function up(): void
    {
        Schema::create('addon_licences', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->text('key')->nullable();
            $table->string('licence_id', 32)->nullable();
            $table->string('licensed_to')->nullable();
            $table->date('issued_on')->nullable();
            $table->date('updates_until')->nullable();
            $table->string('status', 16)->default('missing');
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->foreignId('entered_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('addon_licences');
    }
};
