<?php

namespace App\Data;

use InvalidArgumentException;

/**
 * A rule script that can't be parsed, with where the problem is.
 */
class RuleScriptSyntaxError extends InvalidArgumentException
{
    public function __construct(string $message, public readonly int $scriptLine, public readonly int $scriptColumn)
    {
        parent::__construct($message);
    }
}
