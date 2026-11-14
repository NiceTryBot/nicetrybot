<?php

namespace App\Support;

/**
 * A destination handler that acts on a scope (a site, a path) rather than on clients: a rule over host
 * and path can name it, and a rule over ip and ja4 can't. Every other destination acts on clients, so it
 * takes only rules over ip and ja4.
 */
interface ScopedRuleDestination
{
    //
}
