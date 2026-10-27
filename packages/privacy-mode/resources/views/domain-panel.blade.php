{{-- The domain page's privacy mode panel, as its own component so picking another stand-in needs nothing from the page. --}}
<livewire:privacy-mode::domain-stand-in :domain="$domain" :key="'privacy-stand-in-'.$domain->id" />
