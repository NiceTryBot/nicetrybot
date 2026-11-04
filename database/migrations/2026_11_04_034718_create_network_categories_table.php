<?php

use App\Actions\Networks\LoadNetworkCategories;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations: what each autonomous system is for, loaded from the shipped data (not under
     * unit tests, which have no use for 110,000 rows). The category is empty only on an administrator's
     * "not set".
     */
    public function up(): void
    {
        Schema::create('network_categories', function (Blueprint $table) {
            $table->unsignedBigInteger('asn')->primary();
            $table->unsignedSmallInteger('category')->nullable();
            $table->unsignedInteger('ranges')->default(0);
            $table->unsignedBigInteger('addresses')->default(0);
            $table->boolean('is_override')->default(false);
        });

        if (! app()->runningUnitTests()) {
            app(LoadNetworkCategories::class)->handle();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('network_categories');
    }
};
