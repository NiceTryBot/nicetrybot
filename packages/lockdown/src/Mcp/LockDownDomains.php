<?php

namespace NiceTry\Lockdown\Mcp;

use App\Enums\ApiAbility;
use App\Models\Domain;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description(<<<'TEXT'
    Put sites into lockdown: within about 10 seconds the edge answers every request to them, GET or POST,
    with a plain Scheduled Maintenance page, and nothing reaches their origins. Only people on the allowed
    IP list (see get-lockdown-status) get through. ALWAYS tell the person which sites will go down for
    visitors and get a clear yes before calling this.

    Pass `hostnames` (the team's sites, like ["shop.example.com"]) or `everything: true` for every site and
    origin the team has. The sites go into the lockdown's pool and the lockdown is engaged, unless you pass
    `engage: false` to only prepare the pool. If the lockdown is already engaged, sites you add are locked
    at once either way. If any hostname isn't one of the team's sites, nothing changes and the error names
    them. Afterwards, release-domains lets sites back out one at a time and lift-lockdown ends it.
    TEXT)]
class LockDownDomains extends LockdownTool
{
    protected ApiAbility $ability = ApiAbility::Lockdown;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'hostnames' => ['nullable', 'array', 'max:500'],
            'hostnames.*' => ['string', 'max:255'],
            'everything' => ['nullable', 'boolean'],
            'engage' => ['nullable', 'boolean'],
        ]);

        $everything = $request->boolean('everything');
        $hostnames = $request->array('hostnames');

        if (! $everything && $hostnames === []) {
            return Response::error('Pass the hostnames to lock down, or everything: true to lock every site.');
        }

        if ($everything && $hostnames !== []) {
            return Response::error('Pass either hostnames or everything: true, not both.');
        }

        $lockdown = $this->lockdown('update');

        if ($everything) {
            $lockdown->lockEverything();
            $locked = [];
        } else {
            [$sites, $unknown] = $this->findSites($hostnames);

            if ($unknown !== []) {
                return Response::error('The team has no site called '.implode(', ', $unknown).'. Nothing was changed. Check the hostnames (get-lockdown-status lists what\'s locked) and try again.');
            }

            DB::transaction(fn () => $sites->each(fn (Domain $site) => $lockdown->lock($site)));
            $locked = $sites->pluck('hostname')->all();
        }

        if ($request->boolean('engage', true)) {
            $lockdown->engage();
        }

        $summary = $this->summary($lockdown);

        return Response::structured([
            'locked' => $everything ? 'everything' : $locked,
            'message' => $summary['engaged']
                ? "The lockdown is engaged: {$summary['locked_count']} of {$summary['site_count']} sites show the maintenance page within about 10 seconds."
                : "{$summary['locked_count']} of {$summary['site_count']} sites are in the pool. The lockdown isn't engaged, so they serve normally until it is (call this again without engage: false).",
            ...$summary,
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'hostnames' => $schema->array()
                ->items($schema->string())
                ->description('The team\'s sites to lock down, by hostname, like ["shop.example.com", "www.example.org"]. Leave out when everything is true.'),
            'everything' => $schema->boolean()
                ->description('True to lock every site and origin the team has, and take back every earlier release. Leave out when passing hostnames.'),
            'engage' => $schema->boolean()
                ->description('Whether to engage the lockdown, so visitors see the maintenance page. Defaults to true; false only prepares the pool.')
                ->default(true),
        ];
    }
}
