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
        Schema::create('edge_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('source');
            $table->boolean('enabled')->default(true);
            $table->unsignedTinyInteger('action');
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedBigInteger('last_request_id')->nullable();
            $table->timestamp('last_evaluated_at')->nullable();
            $table->timestamps();

            $table->index(['enabled', 'team_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('edge_rules');
    }
};
