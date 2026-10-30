<?php

namespace App\Console\Commands;

use App\Enums\ApiAbility;
use App\Mcp\ApiContext;
use App\Mcp\Servers\NiceTryBotServer;
use App\Models\Administrator;
use App\Models\ApiToken;
use App\Models\Team;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Server\Transport\HttpTransport;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The MCP server for someone on the server itself: `nicetrybot domains add …` on a self-hosted server
 * sends the same JSON-RPC message it would send to /mcp from a laptop, and gets the same answer from
 * the same tools. Being root on the server is the credential, so it acts as the team's owner with
 * every ability, without an API token.
 */
#[Signature('nicetry:mcp
    {message? : One JSON-RPC message, like {"jsonrpc":"2.0","id":1,"method":"tools/call",...} (read from standard input when left out)}
    {--team= : The team to work on, by slug or id (the first administrator\'s current team when left out)}')]
#[Description('Send one message to the MCP server as the team\'s owner and print its answer (the nicetrybot command uses it on a self-hosted server)')]
class CallMcpServer extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ApiContext $context): int
    {
        $team = $this->team();

        if ($team === null) {
            $this->error($this->option('team') !== null
                ? "No team called {$this->option('team')}. Teams: ".Team::query()->orderBy('id')->pluck('slug')->implode(', ')
                : 'No team to work on yet: make the first administrator with php artisan nicetry:install.');

            return self::FAILURE;
        }

        $owner = $team->owner();

        if (! $owner instanceof User) {
            $this->error("The team {$team->slug} has no owner to act as.");

            return self::FAILURE;
        }

        $context->use($this->serverToken($team, $owner));
        Auth::setUser($owner);

        $message = $this->argument('message') ?? stream_get_contents(STDIN);

        $this->output->writeln($this->send((string) $message), $this->output::OUTPUT_RAW);

        return self::SUCCESS;
    }

    /**
     * The team asked for, or the first administrator's current team.
     */
    private function team(): ?Team
    {
        $team = $this->option('team');

        if ($team !== null) {
            return Team::query()->where('slug', $team)->when(ctype_digit((string) $team), fn ($query) => $query->orWhere('id', $team))->first();
        }

        return Administrator::query()->oldest('id')->first()?->user?->currentTeam;
    }

    /**
     * A token that's never saved, so the tools see the owner with every ability on the team.
     */
    private function serverToken(Team $team, User $owner): ApiToken
    {
        $token = new ApiToken;

        $token->forceFill([
            'user_id' => $owner->id,
            'team_id' => $team->id,
            'name' => 'nicetrybot on the server',
            'abilities' => array_column(ApiAbility::cases(), 'value'),
            'expires_at' => null,
        ]);

        return $token->setRelation('user', $owner)->setRelation('team', $team);
    }

    /**
     * Run the message through the server as /mcp would, and return its answer.
     */
    private function send(string $message): string
    {
        $transport = new HttpTransport(Request::create('/mcp', 'POST', content: $message));

        app()->make(NiceTryBotServer::class, ['transport' => $transport])->start();

        $response = $transport->run();

        if (! $response instanceof StreamedResponse) {
            return (string) $response->getContent();
        }

        // A streamed answer arrives as server-sent events: the last one is the result.
        ob_start();
        $response->sendContent();
        $events = (string) ob_get_clean();

        preg_match_all('/^data: (.*)$/m', $events, $matches);

        return (string) end($matches[1]);
    }
}
