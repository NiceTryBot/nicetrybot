{{-- While privacy mode is on, the page stays hidden until its script has swapped every real domain for its stand-in, and what the core marks data-privacy-hide is taken off the page. --}}
<style>html.privacy-mode:not(.privacy-ready) body { visibility: hidden; } html.privacy-mode [data-privacy-hide] { display: none !important; }</style>
<script>try { if (localStorage.getItem('nicetrybot:privacy-mode') === '1') document.documentElement.classList.add('privacy-mode'); } catch (e) {}</script>

{{-- A module, so it runs once the page is parsed, as it did in the core's bundle. A new version reloads the page on wire:navigate rather than running twice. --}}
<script type="module" src="{{ route('privacy.script', ['v' => \NiceTry\PrivacyMode\Support\Script::version()]) }}" data-navigate-track></script>
