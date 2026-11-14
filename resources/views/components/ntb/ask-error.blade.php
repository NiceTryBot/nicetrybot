@props(['message'])

@php($place = __('Settings → Artificial Intelligence'))

{{-- An Ask error, escaped, with "Settings → Artificial Intelligence" linked to that page wherever it names it. --}}
<span>{!! str_replace(e($place), '<a href="'.e(route('ai.edit')).'" wire:navigate class="font-medium underline underline-offset-2" data-test="ask-error-settings-link">'.e($place).'</a>', e($message)) !!}</span>
