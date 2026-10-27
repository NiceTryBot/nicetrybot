<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Threat monitors and captures belong to add-ons; installed later, each adds the column itself.
        if (Schema::hasTable('threat_monitors')) {
            Schema::table('threat_monitors', function (Blueprint $table) {
                $table->foreignId('livewire_component_id')->nullable()->constrained();
            });
        }

        if (Schema::hasTable('response_captures')) {
            Schema::table('response_captures', function (Blueprint $table) {
                $table->foreignId('livewire_component_id')->nullable()->constrained();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('response_captures')) {
            Schema::table('response_captures', function (Blueprint $table) {
                $table->dropConstrainedForeignId('livewire_component_id');
            });
        }

        if (Schema::hasTable('threat_monitors')) {
            Schema::table('threat_monitors', function (Blueprint $table) {
                $table->dropConstrainedForeignId('livewire_component_id');
            });
        }
    }
};
