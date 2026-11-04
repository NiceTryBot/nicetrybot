<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: a team's response rewrites, each the text to find and what it becomes, on
     * every domain of the team (no domain) or on one.
     */
    public function up(): void
    {
        Schema::create('response_rewrites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('domain_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('find', 1000);
            $table->text('replace');
            $table->boolean('ignore_case')->default(false);
            $table->unsignedTinyInteger('content');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['team_id', 'enabled']);
            $table->index('domain_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('response_rewrites');
    }
};
