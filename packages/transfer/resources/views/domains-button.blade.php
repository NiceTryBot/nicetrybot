{{-- The Domains page's Transfer button, before Add domain, for those who can change domains. --}}
@if (auth()->user()?->hasTeamPermission($team, \App\Enums\TeamPermission::ManageDomains))
    <flux:button size="sm" icon="arrows-right-left" :href="route('transfer.import')" wire:navigate data-test="open-transfer">{{ __('Transfer') }}</flux:button>
@endif
