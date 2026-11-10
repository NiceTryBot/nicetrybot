<?php

namespace App\Support;

use RuntimeException;

/**
 * A destination clause in a rule script can't be applied: the gym routine can't take the rule, the
 * blackhole is admins-only, and so on. The message is shown to the person saving the rule.
 */
class RuleDestinationError extends RuntimeException
{
    //
}
