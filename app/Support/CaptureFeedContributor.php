<?php

namespace App\Support;

/**
 * Something that tells the edge's capture module about routes, through the one list it polls
 * (/caddy/response-captures), like an add-on's captures or monitors. Contributors are tagged in the
 * container under CaptureFeed::TAG.
 *
 * The edge reads two lists from it. Under captures, each entry is a route to record responses on:
 * {id, hosts, method, path, component, request, watch}. Under tags, each is a route whose requests
 * the edge only names the Livewire component of, recording nothing: {id, hosts, method, path,
 * component}. An entry with no hosts should be left out.
 */
interface CaptureFeedContributor
{
    /**
     * The contributor's entries, by the list they go in. Lists several contributors fill are joined
     * in the order the contributors were tagged.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function contribute(CaptureFeedHosts $hosts): array;
}
