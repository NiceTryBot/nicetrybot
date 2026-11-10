<?php

namespace App\Support;

/**
 * Something that tells the edge's rewrite module what to change in origins' responses, through the one
 * list it polls (/caddy/rewrite-feed), like an add-on's response rewrites. Contributors are tagged in
 * the container under RewriteFeed::TAG.
 *
 * Each entry is one rewrite: on its hosts, every `find` in a response body the edge can read (`in`:
 * html for HTML pages only, text for any text response: HTML, CSS, JavaScript, JSON, XML, plain text)
 * becomes `replace`, ignoring case when `ignore_case` is set. The edge applies them in the order listed.
 * An entry with `assets` (to, extensions, skip_paths) moves the URLs of files with those extensions on
 * its hosts (and root-relative ones) to the origin `to` instead, in pages only, except on
 * pages under skip_paths; its `find` is empty, so an edge that doesn't know `assets` skips it.
 */
interface RewriteFeedContributor
{
    /**
     * The contributor's rewrites.
     *
     * @return array<int, array{id: int, hosts: list<string>, find: string, replace: string, ignore_case: bool, in: string, assets?: array{to: string, extensions: list<string>, skip_paths: list<string>}}>
     */
    public function rewrites(): array;
}
