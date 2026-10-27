<?php

namespace App\Support;

/**
 * Something that tells the edge's lockdown module what to hold, through the one list it polls
 * (/caddy/lockdowns), like an add-on's engaged lockdowns. Contributors are tagged in the container
 * under LockdownFeed::TAG.
 *
 * Each entry is one engaged lockdown: {team, hosts, allow, secret, html, retry_after}. The edge answers
 * every request to the hosts with html (a 503 with Retry-After: retry_after), except from the allow
 * list's IPs and CIDR ranges and from browsers holding a pass signed with the secret.
 */
interface LockdownFeedContributor
{
    /**
     * The contributor's entries, for the edge at the given address, which has just asked for them.
     * Lists several contributors fill are joined in the order the contributors were tagged.
     *
     * @return list<array{team: int, hosts: list<string>, allow: list<string>, secret: string, html: string, retry_after: int}>
     */
    public function lockdowns(string $edge): array;
}
