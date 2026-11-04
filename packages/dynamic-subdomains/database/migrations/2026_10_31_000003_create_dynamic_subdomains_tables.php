<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: the domains that add their subdomains as they're visited (a row each), and
     * which domain added each subdomain.
     */
    public function up(): void
    {
        Schema::create('dynamic_subdomain_parents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('dynamic_subdomains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_domain_id')->constrained('domains')->cascadeOnDelete();
            $table->foreignId('domain_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index('parent_domain_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dynamic_subdomains');
        Schema::dropIfExists('dynamic_subdomain_parents');
    }
};
