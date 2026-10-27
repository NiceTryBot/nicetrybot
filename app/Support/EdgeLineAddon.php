<?php

namespace App\Support;

/**
 * Something a package keeps from the edge's log lines, beside the request each line becomes, like a
 * captured response. Add-ons are tagged in the container under EdgeLineAddons::TAG.
 *
 * The import (App\Actions\Edge\StoreEdgeLogLines) asks every add-on to read each line it stores
 * (read), then which of what it read to keep, once a batch's duplicates are dropped and each row's
 * domain is known (keep), and once the batch's requests are stored, hands over what's kept with each
 * request's id (store). All three run inside the import's transaction, once per batch for keep and
 * store, so an add-on should look things up for the whole batch at once. A row something keeps is
 * inserted on its own to learn its id; the rest go in batches, so keep only what will be stored.
 */
interface EdgeLineAddon
{
    /**
     * Read what the add-on wants from one log line, or null when there's nothing for it. The line has
     * already been checked as a request on a known domain.
     *
     * @param  array<string, mixed>  $line
     */
    public function read(array $line): mixed;

    /**
     * Pick what to keep among what was read, now that each row's domain is known.
     *
     * @param  array<int, array{domain_id: int, read: mixed}>  $rows  What read() returned for each row, by row.
     * @return array<int, mixed> What to store, keyed like the rows given; a row left out keeps nothing.
     */
    public function keep(array $rows): array;

    /**
     * Store what was kept, now that each request has its id.
     *
     * @param  array<int, mixed>  $kept  What keep() returned, by edge request id, in the batch's order.
     */
    public function store(array $kept): void;
}
