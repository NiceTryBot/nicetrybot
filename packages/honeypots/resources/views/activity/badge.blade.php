@php($label = $hit->honeypot === null ? __('Honeypot') : __('Honeypot: :name', ['name' => $hit->honeypot->name]))
<flux:tooltip :content="$label" position="top">
    <flux:badge size="sm" color="amber" icon="bug-ant" class="cursor-help" data-test="honeypot-badge">{{ __('Honeypot') }}</flux:badge>
</flux:tooltip>
