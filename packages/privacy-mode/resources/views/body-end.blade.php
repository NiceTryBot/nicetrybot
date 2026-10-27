{{-- The real hostname → stand-in map privacy mode swaps with (resources/js/privacy.js), loaded as a script
     versioned by the team's domains so the browser caches it instead of every page carrying it. --}}
@php($team = auth()->user()?->currentTeam)
@if ($team)
    <script src="{{ route('privacy.hostnames', ['current_team' => $team->slug, 'v' => \NiceTry\PrivacyMode\Support\HostnameMap::versionFor($team)]) }}"></script>
@endif

{{-- The words privacy mode hides on every page, for every team, with what shows in their place. --}}
<script type="text/plain" data-privacy-words>{{ base64_encode(json_encode((object) \NiceTry\PrivacyMode\Models\PrivacyWord::map())) }}</script>
