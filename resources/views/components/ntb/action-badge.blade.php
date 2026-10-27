@props(['action'])

<flux:badge size="sm" :color="$action->color()" :icon="$action->icon()" {{ $attributes }}>{{ $action->label() }}</flux:badge>
