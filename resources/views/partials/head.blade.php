<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
<link rel="icon" type="image/png" sizes="512x512" href="{{ asset('icon-512.png') }}">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

{{-- Naming the family inlines its CSS from the manifest instead of reading the CSS file from disk, which Vapor doesn't ship to Lambda. --}}
@fonts('instrument-sans')

{{-- Add-ons' styles and scripts, from App\Support\ViewSlots. --}}
{{ app(\App\Support\ViewSlots::class)->render(\App\Enums\ViewSlot::Head) }}

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
