<?php

namespace App\Support;

/**
 * Something that tells the edge's honeypot module where to send trapped 404s, through the one list it
 * polls (/caddy/honeypot-feed), like the Honeypots add-on's traps. Contributors are tagged in the
 * container under HoneypotFeed::TAG.
 *
 * Each entry is one trap: on its hosts, a request whose path fits one of its `globs` (the whole path, in
 * any case, `*` matching anything) or `regexes` (anywhere in the path, as written) and that the site's
 * origin answers with a 404 is answered by the honeypot at `origin` instead, asked with `secret` as
 * X-NiceTry-Origin-Secret and given `timeout` seconds. `honeypot` is the honeypot's id, logged with the
 * trap's `id`.
 */
interface HoneypotFeedContributor
{
    /**
     * The contributor's traps.
     *
     * @return array<int, array{id: int, honeypot: int, hosts: list<string>, origin: string, secret: string, timeout: int, globs: list<string>, regexes: list<string>}>
     */
    public function honeypots(): array;
}
