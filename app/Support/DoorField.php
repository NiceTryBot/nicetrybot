<?php

namespace App\Support;

use App\Data\LiveRequest;
use App\Models\Domain;

/**
 * A rule field a front gate can know about the request in front of it, though the edge can't: whether
 * the site or path is in Swarm Mode, say. A gate that judges rules at the door (the Bouncer) reads each
 * one it's given into the live request, so a rule testing it can be decided there.
 */
interface DoorField
{
    /**
     * The field the value is for.
     */
    public function field(): RuleFieldDefinition;

    /**
     * The field's value for this request to this domain, in the form rules store it, or null.
     *
     * @return int|string|list<string>|null
     */
    public function value(Domain $domain, LiveRequest $live): int|string|array|null;
}
