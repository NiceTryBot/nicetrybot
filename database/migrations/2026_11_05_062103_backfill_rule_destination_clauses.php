<?php

use App\Actions\Rules\BackfillRuleDestinations;
use Illuminate\Database\Migrations\Migration;

/**
 * Destinations now live in the rule script. Write each rule's current gym routines, tarpits and
 * blackhole feeder into its source, so the first edit under the new model keeps them. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(BackfillRuleDestinations::class)->handle();
    }

    public function down(): void
    {
        // Destination clauses in a script are harmless to leave; there is nothing to undo.
    }
};
