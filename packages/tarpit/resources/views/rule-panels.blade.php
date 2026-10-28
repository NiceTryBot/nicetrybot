{{-- The open rule's tarpits, on the Rules page (ViewSlot::RulePanels). --}}
<livewire:tarpit::rule-panel :rule="$rule" :wire:key="'tarpit-rule-panel-'.$rule->id" />
