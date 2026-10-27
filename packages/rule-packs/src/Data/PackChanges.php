<?php

namespace NiceTry\RulePacks\Data;

/**
 * What installing, updating or uninstalling a pack did, by the names of the rules and routines: what
 * it added, brought up to date, removed, left alone because the team had changed it, and couldn't
 * install because an add-on it needs isn't installed. Rules start switched off, rather than tagging,
 * when one of the team's routines takes every rule's verdicts.
 */
readonly class PackChanges
{
    /**
     * @param  list<string>  $added
     * @param  list<string>  $updated
     * @param  list<string>  $removed
     * @param  list<string>  $kept
     * @param  list<string>  $unavailable
     */
    public function __construct(
        public array $added = [],
        public array $updated = [],
        public array $removed = [],
        public array $kept = [],
        public array $unavailable = [],
        public bool $rulesStartOff = false,
    ) {
        //
    }
}
