<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: words privacy mode hides everywhere in the app, for every team, like a client's
     * name inside a path. Each can name what shows in its place.
     */
    public function up(): void
    {
        Schema::create('privacy_words', function (Blueprint $table) {
            $table->id();
            $table->string('word', 100)->unique();
            $table->string('replacement', 100)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('privacy_words');
    }
};
