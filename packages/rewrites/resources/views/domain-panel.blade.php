{{-- The domain page's Rewrites panel (ViewSlot::DomainPanels), as its own component so editing needs nothing from the page. --}}
<livewire:rewrites::domain-panel :domain="$domain" :key="'rewrites-domain-panel-'.$domain->id" />
