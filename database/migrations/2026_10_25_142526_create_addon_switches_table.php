<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: what administrators changed about an installed add-on, by its slug. No row
     * means it's on, as it was deployed. Turning one off takes effect on every server at once, since
     * each request reads the switches; removing one is asked for here and done by the next deploy, so
     * the row outlives the package until someone dismisses it.
     */
    public function up(): void
    {
        Schema::create('addon_switches', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->timestamp('turned_off_at')->nullable();
            $table->foreignId('turned_off_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('removal_requested_at')->nullable();
            $table->foreignId('removal_requested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('addon_switches');
    }
};
