<?php

namespace NiceTry\Lockdown\Mcp;

use App\Enums\ApiAbility;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description(<<<'TEXT'
    Lift the lockdown: within about 10 seconds every site serves visitors normally again. The pool (which
    sites and origins are locked) is kept as it is; to lock down again, call lock-down-domains with the
    hostnames (or everything: true). Takes no input. Tell the person every locked site will come back up
    and get a yes before calling this. Calling it when the lockdown isn't engaged changes nothing.
    TEXT)]
class LiftLockdown extends LockdownTool
{
    protected ApiAbility $ability = ApiAbility::Lockdown;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): ResponseFactory
    {
        $lockdown = $this->lockdown('update');
        $wasEngaged = $lockdown->isEngaged();

        if ($wasEngaged) {
            $lockdown->lift();
        }

        return Response::structured([
            'message' => $wasEngaged
                ? 'Lockdown lifted. Every site serves normally within about 10 seconds.'
                : 'The lockdown wasn\'t engaged, so nothing changed.',
            ...$this->summary($lockdown),
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
