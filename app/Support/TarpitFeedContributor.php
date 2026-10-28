<?php

namespace App\Support;

/**
 * Something that tells the edge's tarpit module who to make wait, through the one list it polls
 * (/caddy/tarpit-feed), like an add-on's tarpits. Contributors are tagged in the container under
 * TarpitFeed::TAG.
 *
 * Each entry is one tarpit: on its hosts, a client in it (listed in clients, matching one of its rules'
 * single-request expressions, or over its not_found or burst limit by the edge's own count) gets `free`
 * tries, then waits each of `delays` in turn (seconds), never longer than `cap`, before the request goes
 * on (`after`: continue, not_found or hangup). At most `max_held` requests wait at once on an edge; past
 * that `when_full` says what happens (reject with a 429, pass, or hangup). A client quiet for `forget`
 * seconds starts over.
 */
interface TarpitFeedContributor
{
    /**
     * The contributor's tarpits, for the edge at the given address, which has just asked for them.
     *
     * @return array<int, array<string, mixed>> Each with the keys above: id, hosts, free, delays, cap,
     *                                          max_held, when_full, after, forget, clients ({ip, ja4, why}),
     *                                          rules ({id, expr}), not_found and burst ({count, within} or null).
     */
    public function tarpits(string $edge): array;
}
