<?php

namespace NiceTry\Transfer;

/**
 * What an import did: the origin created, updated or left as it was, and for its sites, the team's
 * rules and its routines, what was added, updated, left alone or couldn't come across, and why.
 */
final class ImportSummary
{
    /** @var 'created'|'updated'|'unchanged' */
    public string $origin = 'unchanged';

    public string $originName = '';

    public int $originId = 0;

    public ?string $source = null;

    public int $sitesAdded = 0;

    public int $sitesUnchanged = 0;

    public int $sitesUpdated = 0;

    /** @var list<string> Hostnames another team on this panel already has. */
    public array $sitesTaken = [];

    /** @var list<string> Hostnames this team already has, not as a site of this origin: left as they are. */
    public array $sitesElsewhere = [];

    /** @var array{added: list<string>, updated: list<string>, unchanged: list<string>, unavailable: list<string>} */
    public array $rules = ['added' => [], 'updated' => [], 'unchanged' => [], 'unavailable' => []];

    /** @var array{added: list<string>, updated: list<string>, unchanged: list<string>, skipped: list<string>} */
    public array $routines = ['added' => [], 'updated' => [], 'unchanged' => [], 'skipped' => []];

    /** @var list<string> Obstacle keys routines use that this panel doesn't have (an add-on not installed or off). */
    public array $missingObstacles = [];

    /**
     * The summary as plain values, for a page to keep and show.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
