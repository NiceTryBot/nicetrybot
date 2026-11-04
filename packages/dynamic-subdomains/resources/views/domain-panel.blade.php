{{-- The domain page's Dynamic subdomains panel (ViewSlot::DomainPanels), as its own component so switching it needs nothing from the page. --}}
<livewire:dynamic-subdomains::domain-panel :domain="$domain" :key="'dynamic-subdomains-domain-panel-'.$domain->id" />
