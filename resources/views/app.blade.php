@php
    $theme = app(\App\Support\ThemeStyles::class);
    $themeCss = $theme->css();
    $companyName = settings('company.name');
@endphp
<!DOCTYPE html>
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    class="h-full"
    @foreach ($theme->htmlAttributes(auth()->user()) as $attribute => $value) {{ $attribute }}="{{ $value }}" @endforeach
>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title inertia>{{ $companyName }}</title>

    {{-- The tab icon. The square versions are made from the same logo, so
         the mark is not squashed into a square box by the browser. --}}
    {{-- An uploaded logo brings its own icons, cut by App\Support\SettingImage;
         otherwise the ones shipped in public/images are used. --}}
    @if (settings('company.logo'))
        @php($uploadedLogo = \App\Support\SettingImage::isUploaded(settings('company.logo')))
        <link rel="icon" type="image/png" sizes="64x64" href="{{ ($uploadedLogo ? \App\Support\SettingImage::iconUrl('favicon') : null) ?? asset('images/favicon.png') }}">
        <link rel="apple-touch-icon" href="{{ ($uploadedLogo ? \App\Support\SettingImage::iconUrl('apple-touch-icon') : null) ?? asset('images/apple-touch-icon.png') }}">
    @endif

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:wght@500;600;700&display=swap" rel="stylesheet">

    {{-- The theme settings, written by the server so the app is never seen
         in the wrong colour for a moment. --}}
    @if ($themeCss !== '')
        <style id="maf-theme">{!! $themeCss !!}</style>
    @endif

    {{-- "Same as my device" (phase 35.1): only the browser knows the
         device's setting, so it is read here, before anything is painted,
         and kept up to date if the device switches while the page is open.
         resources/js/lib/appearance.js does the same after every visit. --}}
    <script>
        (function () {
            try {
                var root = document.documentElement;
                var query = window.matchMedia('(prefers-color-scheme: dark)');
                var follow = function () {
                    if (root.dataset.appearance === 'system') {
                        root.dataset.theme = query.matches ? 'dark' : 'light';
                    }
                };
                follow();
                query.addEventListener('change', follow);
            } catch (e) {}
        })();
    </script>

    {{-- Paint the saved sidebar state before Vue boots, so the layout never
         flashes in the wrong shape on reload. The cookie wins; the theme
         setting is the fallback for somebody who has never folded it. --}}
    <script>
        (function () {
            try {
                var m = document.cookie.match(/(?:^|; )sidebar_collapsed=([^;]*)/);
                var collapsed = m ? m[1] === '1' : {{ settings('theme.sidebar_collapsed') ? 'true' : 'false' }};
                if (collapsed) {
                    document.documentElement.dataset.sidebar = 'collapsed';
                }
            } catch (e) {}
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
{{-- A guest only ever sees the navy sign-in shell, so the page starts navy.
     Without this the browser paints the light canvas for a moment before Vue
     mounts, and the first screen anybody sees flashes white. --}}
<body class="h-full font-sans antialiased text-ink {{ auth()->check() ? 'bg-canvas' : 'bg-navy-900' }}">
    @inertia
</body>
</html>
