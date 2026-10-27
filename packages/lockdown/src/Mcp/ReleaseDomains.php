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
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description(<<<'TEXT'
    Release sites from the lockdown, so they serve visitors normally again (within about 10 seconds while
    it's engaged) while the rest stay locked. Pass `hostnames`, the team's sites to release. A site of an
    origin that's locked as a whole is released on its own and the origin stays locked. Tell the person
    which sites will come back up and get a yes before calling this. If any hostname isn't one of the
    team's sites, nothing changes and the error names them. To end the lockdown for every site at once,
    call lift-lockdown instead; get-lockdown-status shows what's still locked.
    TEXT)]
class ReleaseDomains extends LockdownTool
{
    protected ApiAbility $ability = ApiAbility::Lockdown;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'hostnames' => ['required', 'array', 'min:1', 'max:500'],
            'hostnames.*' => ['string', 'max:255'],
        ]);

        $lockdown = $this->lockdown('update');

        [$sites, $unknown] = $this->findSites($request->array('hostnames'));

        if ($unknown !== []) {
            return Response::error('The team has no site called '.implode(', ', $unknown).'. Nothing was changed. Check the hostnames (get-lockdown-status lists what\'s locked) and try again.');
        }

        DB::transaction(fn () => $sites->each(fn (Domain $site) => $lockdown->release($site)));

        $summary = $this->summary($lockdown);

        return Response::structured([
            'released' => $sites->pluck('hostname')->all(),
            'message' => $summary['engaged']
                ? "Released. They serve normally within about 10 seconds; {$summary['locked_count']} of {$summary['site_count']} sites stay locked."
                : "Released from the pool. The lockdown isn't engaged, so every site serves normally anyway.",
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
                ->description('The team\'s sites to release, by hostname, like ["shop.example.com"].')
                ->required(),
        ];
    }
}
