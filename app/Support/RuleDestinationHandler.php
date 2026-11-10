<?php

namespace App\Support;

use App\Models\EdgeRule;
use App\Models\Team;

/**
 * A destination a rule script can name as a clause, e.g. GYM 'Curl probes', TARPIT 'curl-probes' or
 * BLACKHOLE. The core brings the gym; add-ons bring their own (tarpit, blackhole). A handler both
 * knows the keyword and materialises the link into whatever table the edge reads, since the edge never
 * reads the destination from the script itself.
 */
interface RuleDestinationHandler
{
    /**
     * The clause keyword, uppercase, e.g. 'GYM'.
     */
    public function keyword(): string;

    /**
     * Whether the clause takes a quoted target name (GYM 'routine', TARPIT 'name') or stands alone
     * (BLACKHOLE).
     */
    public function takesTarget(): bool;

    /**
     * Whether the person may add or remove this destination on the team. When false, the save leaves
     * the rule's existing link to this destination untouched (reconciliation skips it), and a clause
     * naming it is refused by apply().
     */
    public function managedBy(Team $team): bool;

    /**
     * Link the rule to this destination, creating the target by name if it doesn't exist yet. $target is
     * the quoted name, or null for a keyword that takes none. Throw RuleDestinationError to refuse.
     *
     * @throws RuleDestinationError
     */
    public function apply(EdgeRule $rule, ?string $target): void;

    /**
     * Remove this rule's link to this destination, if any.
     */
    public function remove(EdgeRule $rule): void;

    /**
     * One phrase for describe(), e.g. "send its clients through the gym routine “Curl probes”".
     */
    public function phrase(?string $target): string;
}
