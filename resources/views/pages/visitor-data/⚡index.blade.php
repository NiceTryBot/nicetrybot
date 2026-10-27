<?php

use App\Actions\VisitorData\ForgetVisitor;
use App\Enums\TlsFingerprintType;
use App\Models\GymSession;
use App\Models\Team;
use App\Models\Verdict;
use App\Models\VisitorDataSetting;
use App\Models\VisitorErasure;
use App\Support\RetentionPeriod;
use App\Support\VisitorDataHolders;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Visitor data')] class extends Component {
    /**
     * How many days each kind of data is kept, by its RetentionPeriod's field.
     *
     * @var array<string, int>
     */
    public array $days = [];

    public string $contact = '';

    /**
     * A gym reference, IP address or JA4 fingerprint to look a visitor up by.
     */
    #[Url(as: 'q', except: '')]
    public string $lookup = '';

    public function mount(): void
    {
        Gate::authorize('view', [VisitorDataSetting::class, $this->team]);

        $settings = VisitorDataSetting::for($this->team);
        $this->days = collect($this->periods)->mapWithKeys(fn (RetentionPeriod $period) => [$period->field => (int) $settings->{$period->field}])->all();
        $this->contact = (string) $settings->contact;
    }

    /**
     * The kinds of data a team chooses how long to keep: the core's and every add-on's.
     *
     * @return list<RetentionPeriod>
     */
    #[Computed]
    public function periods(): array
    {
        return app(VisitorDataHolders::class)->retention();
    }

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    #[Computed]
    public function canManage(): bool
    {
        return Gate::allows('manage', [VisitorDataSetting::class, $this->team]);
    }

    public function saveRetention(): void
    {
        Gate::authorize('manage', [VisitorDataSetting::class, $this->team]);

        $days = ['required', 'integer', 'min:1', 'max:'.config('visitor_data.max_days')];
        $this->validate(collect($this->periods)->mapWithKeys(fn (RetentionPeriod $period) => [
            'days.'.$period->field => $period->atMost === null ? $days : [...$days, 'lte:days.'.$period->atMost],
        ])->all(), attributes: collect($this->periods)->mapWithKeys(fn (RetentionPeriod $period) => ['days.'.$period->field => strtolower(__($period->label))])->all());

        $this->save(collect($this->periods)->mapWithKeys(fn (RetentionPeriod $period) => [$period->field => (int) $this->days[$period->field]])->all());

        Flux::toast(variant: 'success', text: __('Saved. Anything older is deleted within the hour.'));
    }

    public function saveContact(): void
    {
        Gate::authorize('manage', [VisitorDataSetting::class, $this->team]);

        $this->contact = trim($this->contact);
        $this->validate([
            'contact' => ['nullable', 'string', 'max:255', function (string $attribute, string $value, Closure $fail) {
                if (filter_var($value, FILTER_VALIDATE_EMAIL) === false && ! (filter_var($value, FILTER_VALIDATE_URL) !== false && str_starts_with($value, 'https://'))) {
                    $fail(__('Enter an email address, or a link starting with https://.'));
                }
            }],
        ]);

        $this->save(['contact' => $this->contact === '' ? null : $this->contact]);

        Flux::toast(variant: 'success', text: $this->contact === '' ? __('Visitors no longer see a contact.') : __('Visitors the gym holds or turns away now see this contact.'));
    }

    /**
     * What the lookup found: the gym sessions it names (a reference names one; an IP or fingerprint
     * names its latest few) and the verdicts in force on that visitor. A visitor is its IP address
     * (a reference stands for its session's IP); a JA4 only when one was typed, since everyone with
     * the same browser and system shares it.
     *
     * @return array{ip: string|null, ja4: string|null, sessions: Collection<int, GymSession>, verdicts: Collection<int, Verdict>}|null
     */
    #[Computed]
    public function found(): ?array
    {
        $lookup = trim($this->lookup);

        if ($lookup === '') {
            return null;
        }

        $ip = ForgetVisitor::normalizeIp($lookup);
        $ja4 = $ip === null && preg_match('/^[tqd]\d{2}[a-z]\d{4}[a-z0-9]{2}_[0-9a-f]{12}_[0-9a-f]{12}$/i', $lookup) === 1 ? $lookup : null;

        $sessions = $this->team->gymSessions()
            ->where('is_preview', false)
            ->when($ip !== null, fn ($query) => $query->where('client_ip', $ip))
            ->when($ja4 !== null, fn ($query) => $query->where('ja4', $ja4))
            ->when($ip === null && $ja4 === null, fn ($query) => strlen($lookup) >= GymSession::REFERENCE_LENGTH
                ? $query->where('token', 'like', substr($lookup, 0, GymSession::REFERENCE_LENGTH).'%')
                : $query->whereRaw('1 = 0'))
            ->with('domain:id,hostname')
            ->latest('started_at')
            ->limit(10)
            ->get();

        // A reference names a visitor by its session's IP address.
        $ip ??= $ja4 === null ? $sessions->first()?->client_ip : null;
        $ja4Id = $ja4 === null ? null : DB::table('tls_fingerprints')->where('type', TlsFingerprintType::Ja4)->where('value', $ja4)->value('id');

        $verdicts = $ip === null && $ja4Id === null ? new Collection : $this->team->verdicts()
            ->active()
            ->when($ip !== null, fn ($query) => $query->where('client_ip', $ip), fn ($query) => $query->where('ja4_id', $ja4Id))
            ->with('rule:id,name')
            ->latest('last_matched_at')
            ->get();

        return ['ip' => $ip, 'ja4' => $ja4, 'sessions' => $sessions, 'verdicts' => $verdicts];
    }

    /**
     * Lift a verdict on the visitor, as Activity does: rules can reach it again if the visitor keeps matching.
     */
    public function liftVerdict(int $verdictId): void
    {
        Gate::authorize('manage', [VisitorDataSetting::class, $this->team]);

        $this->team->verdicts()->findOrFail($verdictId)->update(['lifted_at' => now()]);

        unset($this->found);
        Flux::toast(variant: 'success', text: __('Lifted. Rules can tag this visitor again if it keeps matching.'));
    }

    /**
     * Erase everything the team holds about the visitor looked up: its IP address, or the fingerprint
     * that was typed in.
     */
    public function erase(ForgetVisitor $forgetVisitor): void
    {
        Gate::authorize('manage', [VisitorDataSetting::class, $this->team]);

        $found = $this->found ?? abort(404);
        abort_if($found['ip'] === null && $found['ja4'] === null, 404);

        $forgetVisitor->request($this->team, Auth::user(), $found['ip'], $found['ja4']);

        $this->lookup = '';
        unset($this->found, $this->erasures);
        Flux::modal('erase-visitor')->close();
        Flux::toast(variant: 'success', text: __('Erasing. The record below shows what went once it’s done.'));
    }

    /**
     * @return Collection<int, VisitorErasure>
     */
    #[Computed]
    public function erasures(): Collection
    {
        return $this->team->visitorErasures()->with('user:id,name')->latest()->limit(20)->get();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function save(array $attributes): void
    {
        $settings = VisitorDataSetting::for($this->team);
        $settings->fill($attributes)->save();
    }
}; ?>

<div>
    <x-ntb.page-heading
        :overline="__('Privacy')"
        :title="__('Visitor data')"
        :description="__('How long your visitors’ data is kept, who they can contact when the gym stops them, and erasing one visitor on request. Nothing here leaves your servers.')"
    />

    <div class="grid gap-6 xl:grid-cols-2">
        {{-- Retention --}}
        <x-ntb.panel :heading="__('How long it’s kept')" :subheading="__('Older data is deleted every hour. Verdicts count from when they expired or were lifted; a verdict in force is kept.')" data-test="retention">
            <form wire:submit="saveRetention" class="space-y-4">
                @foreach ($this->periods as $period)
                    <div class="flex items-center gap-4" wire:key="retention-{{ $period->field }}">
                        <div class="min-w-0 flex-1">
                            <label for="retention-{{ $period->field }}" class="text-sm font-medium">{{ __($period->label) }}</label>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __($period->help) }}</p>
                            @error('days.'.$period->field) <p class="mt-1 text-xs text-rose-700 dark:text-rose-300">{{ $message }}</p> @enderror
                        </div>
                        <div class="flex items-center gap-2">
                            <input id="retention-{{ $period->field }}" type="number" min="1" max="{{ config('visitor_data.max_days') }}" wire:model="days.{{ $period->field }}" @disabled(! $this->canManage) class="w-20 rounded-md border border-line bg-white px-2 py-1 text-end font-mono text-sm tabular-nums dark:border-zinc-700 dark:bg-zinc-900" data-test="retention-{{ $period->field }}" />
                            <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('days') }}</span>
                        </div>
                    </div>
                @endforeach

                @if ($this->canManage)
                    <div class="flex justify-end">
                        <flux:button type="submit" size="sm" variant="primary" data-test="save-retention">{{ __('Save') }}</flux:button>
                    </div>
                @endif
            </form>
        </x-ntb.panel>

        {{-- Contact --}}
        <x-ntb.panel :heading="__('Contact for visitors')" :subheading="__('Shown under a gym challenge and on the page a turned-away visitor sees, with a reference you can look up below. Leave it empty to show nothing.')" data-test="visitor-contact">
            <form wire:submit="saveContact" class="space-y-3">
                <flux:input wire:model="contact" :placeholder="__('help@example.com or https://example.com/contact')" size="sm" spellcheck="false" :disabled="! $this->canManage" data-test="contact" />
                @error('contact') <p class="text-xs text-rose-700 dark:text-rose-300">{{ $message }}</p> @enderror

                <div class="rounded-lg border border-dashed border-line p-3 text-center text-xs text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
                    <span class="font-mono text-[0.65rem] tracking-widest uppercase">{{ __('Visitors see') }}</span>
                    <p class="mt-1">
                        @if (trim($contact) !== '')
                            {!! __('Having trouble? Contact :contact and quote reference :reference.', ['contact' => '<span class="underline">'.e(trim($contact)).'</span>', 'reference' => '<span class="font-mono">Xy7Kq2Rw9b</span>']) !!}
                        @else
                            {{ __('Nothing.') }}
                        @endif
                    </p>
                </div>

                @if ($this->canManage)
                    <div class="flex justify-end">
                        <flux:button type="submit" size="sm" data-test="save-contact">{{ __('Save') }}</flux:button>
                    </div>
                @endif
            </form>
        </x-ntb.panel>
    </div>

    {{-- Find a visitor --}}
    <x-ntb.panel class="mt-6" :heading="__('Find a visitor')" :subheading="__('Look a visitor up by the reference the gym showed them, their IP address or their JA4 fingerprint. Then lift what’s stopping them, or erase them.')" data-test="find-visitor">
        <flux:input wire:model.live.debounce.400ms="lookup" icon="magnifying-glass" :placeholder="__('Reference, IP address or JA4')" size="sm" class="max-w-md" spellcheck="false" data-test="lookup" />

        @if ($found = $this->found)
            <div class="mt-5 space-y-5" data-test="lookup-results">
                @if ($found['ip'] === null && $found['ja4'] === null)
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No gym visit has that reference.') }}</p>
                @else
                    <div class="flex flex-wrap items-center gap-3 text-sm">
                        @if ($found['ip'])
                            <a href="{{ route('activity.index', ['ip' => $found['ip'], 'period' => '30d']) }}" wire:navigate class="font-mono hover:underline"><x-ntb.label kind="ip" :value="$found['ip']" /></a>
                        @endif
                        @if ($found['ja4'])
                            <span class="font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $found['ja4'] }}</span>
                        @endif
                        @if ($this->canManage)
                            <flux:modal.trigger name="erase-visitor">
                                <flux:button size="xs" variant="danger" icon="trash" class="ms-auto" data-test="erase-visitor">{{ __('Erase this visitor') }}</flux:button>
                            </flux:modal.trigger>
                        @endif
                    </div>

                    <div>
                        <p class="mb-2 text-xs font-medium tracking-wide text-zinc-500 uppercase dark:text-zinc-400">{{ __('Verdicts in force') }}</p>
                        @forelse ($found['verdicts'] as $verdict)
                            <div class="flex items-center gap-3 border-t border-line py-2 text-sm first:border-t-0 dark:border-zinc-800" wire:key="found-verdict-{{ $verdict->id }}" data-test="found-verdict">
                                <flux:badge size="sm" :color="$verdict->action->color()">{{ $verdict->action->label() }}{{ $verdict->tag ? ' · '.$verdict->tag : '' }}</flux:badge>
                                <span class="min-w-0 flex-1 truncate text-zinc-500 dark:text-zinc-400">{{ $verdict->rule?->name ?? __('Deleted rule') }}</span>
                                <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $verdict->expires_at ? __('ends :time', ['time' => $verdict->expires_at->diffForHumans()]) : __('never ends') }}</span>
                                @if ($this->canManage)
                                    <flux:button size="xs" variant="ghost" wire:click="liftVerdict({{ $verdict->id }})" data-test="lift-found-verdict">{{ __('Lift') }}</flux:button>
                                @endif
                            </div>
                        @empty
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('None. Nothing is holding this visitor back.') }}</p>
                        @endforelse
                    </div>

                    <div>
                        <p class="mb-2 text-xs font-medium tracking-wide text-zinc-500 uppercase dark:text-zinc-400">{{ __('Gym visits') }}</p>
                        @forelse ($found['sessions'] as $session)
                            <div class="flex items-center gap-3 border-t border-line py-2 text-sm first:border-t-0 dark:border-zinc-800" wire:key="found-session-{{ $session->id }}" data-test="found-session">
                                <span class="font-mono text-xs">{{ $session->reference() }}</span>
                                <span class="min-w-0 flex-1 truncate">{{ $session->domain?->hostname ?? __('Unknown site') }}</span>
                                <flux:badge size="sm">{{ $session->status->label() }}</flux:badge>
                                <span class="text-xs text-zinc-500 dark:text-zinc-400" title="{{ $session->started_at->toDayDateTimeString() }}">{{ $session->started_at->diffForHumans() }}</span>
                            </div>
                        @empty
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('None.') }}</p>
                        @endforelse
                    </div>
                @endif
            </div>

            <flux:modal name="erase-visitor" class="max-w-md">
                <div class="space-y-4">
                    <flux:heading size="lg">{{ __('Erase this visitor?') }}</flux:heading>
                    @if ($found['ip'])
                        <flux:text>{{ __('Deletes everything kept about :ip: its requests, its gym visits and what was recorded in them, its verdicts and its labels. It can’t be undone, and rules can tag the visitor again if it comes back.', ['ip' => $found['ip']]) }}</flux:text>
                    @else
                        <flux:text>{{ __('Deletes everything kept with this JA4: requests, gym visits and what was recorded in them, verdicts and labels. It can’t be undone.') }}</flux:text>
                        <flux:callout variant="warning" icon="exclamation-triangle" class="mt-2">
                            <flux:callout.text>{{ __('A JA4 belongs to a browser and system, not a person: everyone on the same build shares it. Erase by IP address unless this fingerprint is a bot’s own.') }}</flux:callout.text>
                        </flux:callout>
                    @endif
                    <div class="flex justify-end gap-2">
                        <flux:modal.close><flux:button variant="ghost" size="sm">{{ __('Cancel') }}</flux:button></flux:modal.close>
                        <flux:button variant="danger" size="sm" wire:click="erase" data-test="confirm-erase">{{ __('Erase') }}</flux:button>
                    </div>
                </div>
            </flux:modal>
        @endif
    </x-ntb.panel>

    {{-- Erasures --}}
    <x-ntb.panel class="mt-6" :heading="__('Erasures')" :subheading="__('A record that each request to erase a visitor was carried out. Visitors are kept only as a keyed hash, so the record doesn’t undo the erasure.')" data-test="erasures">
        <div @if ($this->erasures->contains(fn ($erasure) => $erasure->completed_at === null)) wire:poll.5s @endif>
            @forelse ($this->erasures as $erasure)
                <div class="flex items-center gap-3 border-t border-line py-2 text-sm first:border-t-0 dark:border-zinc-800" wire:key="erasure-{{ $erasure->id }}" data-test="erasure">
                    <span class="font-mono text-xs text-zinc-400" title="{{ $erasure->subject_hash }}">{{ substr($erasure->subject_hash, 0, 10) }}</span>
                    <span class="min-w-0 flex-1 truncate">{{ $erasure->user?->name ?? __('Someone') }}</span>
                    @if ($erasure->completed_at)
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ trans_choice(':count record erased|:count records erased', $erasure->deletedCount(), ['count' => number_format($erasure->deletedCount())]) }}</span>
                    @else
                        <flux:badge size="sm" color="amber" icon="arrow-path">{{ __('Erasing') }}</flux:badge>
                    @endif
                    <span class="text-xs text-zinc-500 dark:text-zinc-400" title="{{ $erasure->created_at->toDayDateTimeString() }}">{{ $erasure->created_at->diffForHumans() }}</span>
                </div>
            @empty
                <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No visitor has been erased yet.') }}</p>
            @endforelse
        </div>
    </x-ntb.panel>
</div>
