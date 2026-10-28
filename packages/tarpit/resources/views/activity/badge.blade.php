@php($label = $hold->tarpit === null ? __('Tarpit') : __('Tarpit: :name', ['name' => $hold->tarpit->name]))
<flux:tooltip :content="$label" position="top">
    <flux:badge size="sm" color="violet" icon="clock" class="cursor-help" data-test="tarpit-badge">{{ __('Tarpit') }}</flux:badge>
</flux:tooltip>
