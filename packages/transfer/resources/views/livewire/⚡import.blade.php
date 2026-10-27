<?php

use App\Enums\TeamPermission;
use App\Models\Team;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use NiceTry\Origins\Models\Origin;
use NiceTry\Transfer\Actions\ImportOrigin;
use NiceTry\Transfer\ImportSummary;
use NiceTry\Transfer\InvalidTransferBundle;
use NiceTry\Transfer\OriginsAddon;
use NiceTry\Transfer\TransferBundle;
use NiceTry\Transfer\TransferSource;

new #[Title('Transfer')] class extends Component
{
    use WithFileUploads;

    public string $panelUrl = '';

    /** The other panel's API token: kept only between finding its origins and pulling one, then cleared. */
    public string $token = '';

    /** @var list<array{id: int, name: string, origin_url: string, sites: int}> */
    public array $remoteOrigins = [];

    public ?TemporaryUploadedFile $file = null;

    /** @var array<string, mixed>|null */
    public ?array $summary = null;

    public ?string $problem = null;

    public function mount(): void
    {
        abort_unless($this->canManage(), 403);
    }

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    #[Computed]
    public function originsOn(): bool
    {
        return OriginsAddon::isOn();
    }

    /**
     * The team's origins, to take to another panel.
     *
     * @return Collection<int, Origin>
     */
    #[Computed]
    public function localOrigins(): Collection
    {
        return $this->originsOn
            ? Origin::query()->where('team_id', $this->team->id)->withCount('domains')->orderBy('name')->get()
            : new Collection;
    }

    /**
     * Ask the other panel which origins its token may transfer.
     */
    public function findOrigins(TransferSource $source): void
    {
        abort_unless($this->canManage(), 403);

        $this->validate([
            'panelUrl' => ['required', 'string', 'max:255'],
            'token' => ['required', 'string', 'max:200'],
        ]);

        $this->reset('problem', 'summary', 'remoteOrigins');

        try {
            $this->remoteOrigins = $source->origins($this->panelUrl, $this->token);
        } catch (InvalidTransferBundle $e) {
            $this->problem = $e->getMessage();

            return;
        }

        if ($this->remoteOrigins === []) {
            $this->problem = __('That panel has no origins on the token\'s team.');
        }
    }

    /**
     * Pull one of the other panel's origins and bring it into this team.
     */
    public function bringHere(int $originId, TransferSource $source, ImportOrigin $import): void
    {
        abort_unless($this->canManage(), 403);
        $this->reset('problem', 'summary');

        try {
            $this->finish($import->handle($this->team, $source->bundle($this->panelUrl, $this->token, $originId)));
        } catch (InvalidTransferBundle $e) {
            $this->problem = $e->getMessage();
        }
    }

    /**
     * Bring the origin in a transfer file into this team.
     */
    public function upload(ImportOrigin $import): void
    {
        abort_unless($this->canManage(), 403);

        $this->validate(['file' => ['required', 'file', 'max:'.(TransferBundle::MAX_BYTES / 1024)]]);
        $this->reset('problem', 'summary');

        try {
            $this->finish($import->handle($this->team, TransferBundle::decode((string) file_get_contents($this->file->getRealPath()))));
        } catch (InvalidTransferBundle $e) {
            $this->problem = $e->getMessage();
        } finally {
            $this->file?->delete();
            $this->file = null;
        }
    }

    /**
     * Keep what the import did to show, and forget the token and the other panel's list.
     */
    private function finish(ImportSummary $summary): void
    {
        $this->summary = $summary->toArray();
        $this->reset('token', 'remoteOrigins');
        unset($this->localOrigins);
    }

    private function canManage(): bool
    {
        return Auth::user()->hasTeamPermission($this->team, TeamPermission::ManageDomains);
    }
}; ?>

<div>
    <x-ntb.page-heading
        :overline="__('Domains')"
        :title="__('Transfer')"
        :description="__('Move an origin from one NiceTryBot to another, such as from the hosted service to your own server: its sites and their settings, its secret (so your app keeps accepting the edge), and this team\'s rules and gym routines. Traffic history stays where it was. Importing again updates what\'s here and never duplicates.')"
    />

    @unless ($this->originsOn)
        <flux:callout icon="exclamation-triangle" color="amber" class="mb-6" data-test="transfer-needs-origins">
            <flux:callout.heading>{{ __('Transfer needs the Origins add-on') }}</flux:callout.heading>
            <flux:callout.text>{{ __('What it moves is an origin, so install Origins on this panel and turn it on in Admin → Add-ons first.') }}</flux:callout.text>
        </flux:callout>
    @endunless

    @if ($problem)
        <flux:callout icon="x-circle" color="red" class="mb-6" data-test="transfer-problem">
            <flux:callout.text>{{ $problem }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($summary)
        <x-ntb.panel class="mb-6" :heading="match ($summary['origin']) {
            'created' => __('Brought :name here', ['name' => $summary['originName']]),
            'updated' => __('Updated :name', ['name' => $summary['originName']]),
            default => __(':name was already up to date', ['name' => $summary['originName']]),
        }" :subheading="$summary['source'] ? __('From :source.', ['source' => $summary['source']]) : null" data-test="transfer-summary">
            <div class="grid gap-4 text-sm sm:grid-cols-3">
                <div>
                    <p class="font-medium text-ink dark:text-white">{{ __('Sites') }}</p>
                    <p class="text-zinc-600 dark:text-zinc-400">{{ __(':added added, :updated updated, :unchanged unchanged', ['added' => $summary['sitesAdded'], 'updated' => $summary['sitesUpdated'], 'unchanged' => $summary['sitesUnchanged']]) }}</p>
                </div>
                <div>
                    <p class="font-medium text-ink dark:text-white">{{ __('Rules') }}</p>
                    <p class="text-zinc-600 dark:text-zinc-400">{{ __(':added added, :updated updated, :unchanged unchanged', ['added' => count($summary['rules']['added']), 'updated' => count($summary['rules']['updated']), 'unchanged' => count($summary['rules']['unchanged'])]) }}</p>
                </div>
                <div>
                    <p class="font-medium text-ink dark:text-white">{{ __('Gym routines') }}</p>
                    <p class="text-zinc-600 dark:text-zinc-400">{{ __(':added added, :updated updated, :unchanged unchanged', ['added' => count($summary['routines']['added']), 'updated' => count($summary['routines']['updated']), 'unchanged' => count($summary['routines']['unchanged'])]) }}</p>
                </div>
            </div>

            @php
                $notes = array_filter([
                    $summary['sitesTaken'] !== [] ? __('Another team here already has :hosts, so they stayed theirs.', ['hosts' => implode(', ', array_slice($summary['sitesTaken'], 0, 10))]) : null,
                    $summary['sitesElsewhere'] !== [] ? __('This team already has :hosts outside this origin; they were left as they are.', ['hosts' => implode(', ', array_slice($summary['sitesElsewhere'], 0, 10))]) : null,
                    $summary['rules']['unavailable'] !== [] ? __('These rules test something this panel hasn\'t got (an add-on), so they didn\'t come across: :rules.', ['rules' => implode(', ', $summary['rules']['unavailable'])]) : null,
                    $summary['routines']['skipped'] !== [] ? __('These routines wait for a verdict from a rule that didn\'t come across, so they were left out: :routines.', ['routines' => implode(', ', $summary['routines']['skipped'])]) : null,
                    $summary['missingObstacles'] !== [] ? __('Some routines use obstacles from an add-on this panel hasn\'t got (:obstacles); the gym skips them until it\'s here.', ['obstacles' => implode(', ', $summary['missingObstacles'])]) : null,
                ]);
            @endphp

            @if ($notes !== [])
                <ul class="mt-4 grid gap-1 text-sm text-amber-700 dark:text-amber-400" data-test="transfer-notes">
                    @foreach ($notes as $note)
                        <li>{{ $note }}</li>
                    @endforeach
                </ul>
            @endif

            <p class="mt-4 text-sm text-zinc-600 dark:text-zinc-400">
                {{ __('Next: point each site\'s DNS at this edge (the records are on each domain\'s page). Certificates follow on their own as the first requests arrive. Until DNS moves, the old edge keeps serving the sites.') }}
            </p>

            <x-slot:actions>
                <flux:button size="sm" icon="server-stack" :href="route('origins.show', $summary['originId'])" wire:navigate>{{ __('Open the origin') }}</flux:button>
            </x-slot:actions>
        </x-ntb.panel>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        <x-ntb.panel :heading="__('Pull from another panel')" :subheading="__('On the other panel, make an API token in Admin → API tokens with “Add and change domains”, on the team that has the origin.')">
            <form wire:submit="findOrigins" class="grid gap-4">
                <flux:input wire:model="panelUrl" :label="__('The other panel\'s address')" placeholder="https://panel.example.com" :disabled="! $this->originsOn" data-test="transfer-panel-url" />
                <flux:input wire:model="token" type="password" :label="__('Its API token')" placeholder="ntb_…" autocomplete="off" :disabled="! $this->originsOn" data-test="transfer-token" />
                <div>
                    <flux:button type="submit" variant="primary" icon="magnifying-glass" :disabled="! $this->originsOn" data-test="transfer-find">{{ __('Find its origins') }}</flux:button>
                </div>
            </form>

            @if ($remoteOrigins !== [])
                <ul class="mt-5 divide-y divide-line border-t border-line dark:divide-zinc-800 dark:border-zinc-800" data-test="transfer-remote-origins">
                    @foreach ($remoteOrigins as $remote)
                        <li class="flex items-center justify-between gap-3 py-3" wire:key="remote-{{ $remote['id'] }}">
                            <div class="min-w-0">
                                <p class="truncate font-medium text-ink dark:text-white">{{ $remote['name'] }}</p>
                                <p class="truncate text-xs text-zinc-500">{{ $remote['origin_url'] }} · {{ trans_choice(':count site|:count sites', $remote['sites']) }}</p>
                            </div>
                            <flux:button size="sm" variant="primary" wire:click="bringHere({{ $remote['id'] }})" data-test="transfer-pull">{{ __('Bring it here') }}</flux:button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ntb.panel>

        <x-ntb.panel :heading="__('Upload a transfer file')" :subheading="__('Download one on the other panel\'s Transfer page, under Take an origin to another panel.')">
            <form wire:submit="upload" class="grid gap-4">
                <flux:input type="file" wire:model="file" accept="application/json,.json" :label="__('Transfer file')" :disabled="! $this->originsOn" data-test="transfer-file" />
                <div>
                    <flux:button type="submit" variant="primary" icon="arrow-up-tray" :disabled="! $this->originsOn" data-test="transfer-upload">{{ __('Bring it here') }}</flux:button>
                </div>
            </form>
        </x-ntb.panel>
    </div>

    @if ($this->localOrigins->isNotEmpty())
        <x-ntb.panel class="mt-6" :heading="__('Take an origin to another panel')" :subheading="__('A transfer file holds the origin\'s secret: keep it like a password, and delete it once it\'s imported.')" data-test="transfer-local-origins">
            <ul class="divide-y divide-line dark:divide-zinc-800">
                @foreach ($this->localOrigins as $origin)
                    <li class="flex items-center justify-between gap-3 py-3" wire:key="local-{{ $origin->id }}">
                        <div class="min-w-0">
                            <p class="truncate font-medium text-ink dark:text-white">{{ $origin->name }}</p>
                            <p class="truncate text-xs text-zinc-500">{{ $origin->origin_url }} · {{ trans_choice(':count site|:count sites', $origin->domains_count) }}</p>
                        </div>
                        <flux:button size="sm" icon="arrow-down-tray" :href="route('transfer.file', $origin->id)" data-test="transfer-download">{{ __('Transfer file') }}</flux:button>
                    </li>
                @endforeach
            </ul>
        </x-ntb.panel>
    @endif
</div>
