<?php

use App\Enums\ApiAbility;
use App\Models\ApiToken;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('API tokens')] class extends Component {
    /**
     * How long a new token may last, in hours. Every token runs out, and the shortest is the default:
     * make one for the job at hand.
     */
    public const array LIFETIMES = [1 => '1 hour', 4 => '4 hours', 24 => '1 day', 168 => '1 week', 720 => '30 days', 2160 => '90 days'];

    public string $name = '';

    public ?int $teamId = null;

    /** @var list<string> */
    public array $abilities = [];

    public int $lifetimeHours = 1;

    /**
     * The token just made, shown once and never again.
     */
    public ?string $plainTextToken = null;

    public function mount(): void
    {
        $this->teamId = Auth::user()->current_team_id;
    }

    /**
     * @return Collection<int, ApiToken>
     */
    #[Computed]
    public function tokens(): Collection
    {
        return ApiToken::query()->whereBelongsTo(Auth::user())->with('team')->latest('id')->get();
    }

    /**
     * The teams a token can be for: the administrator's own.
     *
     * @return Collection<int, \App\Models\Team>
     */
    #[Computed]
    public function teams(): Collection
    {
        return Auth::user()->teams()->orderBy('name')->get();
    }

    /**
     * Make a token for an AI agent, as this administrator on one of their teams. The token is shown
     * once, here; only its hash is kept.
     */
    public function create(): void
    {
        Gate::authorize('administer');

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'teamId' => ['required', 'integer', Rule::in($this->teams->modelKeys())],
            'abilities' => ['array'],
            'abilities.*' => [Rule::enum(ApiAbility::class)],
            'lifetimeHours' => ['required', 'integer', Rule::in(array_keys(self::LIFETIMES))],
        ]);

        [, $this->plainTextToken] = ApiToken::issue(
            Auth::user(),
            $this->teams->find($validated['teamId']),
            $validated['name'],
            array_map(fn (string $ability) => ApiAbility::from($ability), $validated['abilities']),
            now()->addHours($validated['lifetimeHours']),
        );

        $this->reset('name', 'abilities');
        unset($this->tokens);
    }

    /**
     * Stop one of this administrator's tokens working, straight away.
     */
    public function revoke(int $tokenId): void
    {
        Gate::authorize('administer');

        $token = ApiToken::query()->whereBelongsTo(Auth::user())->findOrFail($tokenId);
        $token->delete();

        Flux::toast(text: __(':name no longer works.', ['name' => $token->name]));
        unset($this->tokens);
    }

    /**
     * The command that connects Claude Code to the MCP server with the token just made.
     */
    public function claudeCodeCommand(): string
    {
        return sprintf('claude mcp add --transport http nicetrybot %s --header "Authorization: Bearer %s"', route('mcp'), $this->plainTextToken);
    }

    /**
     * The MCP server entry other agents (Cursor, Claude Desktop, VS Code...) read from their mcp.json.
     */
    public function mcpJson(): string
    {
        return json_encode(['mcpServers' => ['nicetrybot' => [
            'type' => 'http',
            'url' => route('mcp'),
            'headers' => ['Authorization' => 'Bearer '.$this->plainTextToken],
        ]]], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Forget the token just made, once it's been copied.
     */
    public function dismissToken(): void
    {
        $this->plainTextToken = null;
    }
}; ?>

<section class="w-full">
    <x-pages::admin.layout :heading="__('API tokens')" :subheading="__('Tokens let an AI agent, like Claude Code, use NiceTryBot\'s MCP server as you on one of your teams: add domains, ask about traffic, write rules, train gyms. Without a token the server isn\'t there at all.')">
        <x-ntb.copy-field :label="__('MCP server')" :value="route('mcp')" data-test="mcp-url" />

        @if ($plainTextToken !== null)
            <div class="mt-5 rounded-xl border border-emerald-300 bg-emerald-50 p-4 dark:border-emerald-800 dark:bg-emerald-950/40" data-test="new-token">
                <p class="flex items-center gap-2 font-medium text-emerald-700 dark:text-emerald-300">
                    <flux:icon.key variant="mini" class="shrink-0" />
                    {{ __('Copy your token now. It won\'t be shown again.') }}
                </p>
                <p class="mt-1 text-sm text-emerald-700/80 dark:text-emerald-300/80">{{ __('Click any of these to copy it. Run the Claude Code command in your own terminal, not in a chat with an agent, so the token stays out of the conversation.') }}</p>

                <div class="mt-3 space-y-3">
                    <x-ntb.copy-field :label="__('Token')" :value="$plainTextToken" />
                    <x-ntb.copy-field :label="__('Claude Code')" :value="$this->claudeCodeCommand()" />
                    <x-ntb.copy-field :label="__('Other agents (mcp.json)')" :value="$this->mcpJson()" />
                </div>

                <flux:button size="sm" class="mt-4" wire:click="dismissToken">{{ __('I\'ve copied it') }}</flux:button>
            </div>
        @endif

        @if ($this->tokens->isNotEmpty())
            <div class="mt-5 divide-y divide-zinc-200 overflow-hidden rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700" data-test="tokens">
                @foreach ($this->tokens as $token)
                    <div class="flex items-center gap-3 px-4 py-3 text-sm" wire:key="token-{{ $token->id }}">
                        <flux:icon.key variant="mini" class="shrink-0 text-zinc-400" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium text-ink dark:text-white">{{ $token->name }}</p>
                            <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $token->team->name }} ·
                                {{ collect($token->abilities)->map(fn (string $ability) => ApiAbility::tryFrom($ability)?->value)->filter()->implode(', ') }} ·
                                {{ $token->last_used_at !== null ? __('used :when', ['when' => $token->last_used_at->diffForHumans()]) : __('never used') }} ·
                                {{ $token->expires_at !== null ? ($token->expires_at->isPast() ? __('expired') : __('expires :when', ['when' => $token->expires_at->diffForHumans()])) : __('no expiry') }}
                            </p>
                        </div>
                        <flux:button size="xs" variant="ghost" wire:click="revoke({{ $token->id }})" wire:confirm="{{ __('Stop this token working? Any agent using it loses access straight away.') }}" data-test="revoke-token">{{ __('Revoke') }}</flux:button>
                    </div>
                @endforeach
            </div>
        @endif

        <form wire:submit="create" class="mt-6 space-y-5 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <flux:heading>{{ __('Make a token') }}</flux:heading>

            <flux:input wire:model="name" :label="__('Name')" :placeholder="__('Claude Code on my laptop')" :description="__('So you can tell your tokens apart.')" />

            <flux:select wire:model="teamId" :label="__('Team')" :description="__('The token works on this team only, as you.')">
                @foreach ($this->teams as $team)
                    <flux:select.option :value="$team->id">{{ $team->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:checkbox.group wire:model="abilities" :label="__('What it may do')" :description="__('Every token can read. Tick only what the agent needs for this job. It can never do more than you can on the team.')">
                @foreach (ApiAbility::cases() as $ability)
                    @if ($ability !== ApiAbility::Read)
                        <flux:checkbox :value="$ability->value" :label="$ability->label()" />
                    @endif
                @endforeach
            </flux:checkbox.group>

            <flux:select wire:model="lifetimeHours" :label="__('Expires after')" :description="__('Pick the shortest that gets the job done. You can always make another.')">
                @foreach ($this::LIFETIMES as $hours => $label)
                    <flux:select.option :value="$hours">{{ __($label) }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:button type="submit" variant="primary" icon="key">{{ __('Make token') }}</flux:button>
        </form>
    </x-pages::admin.layout>
</section>
