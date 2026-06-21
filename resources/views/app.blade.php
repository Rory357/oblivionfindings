@php
// Pull the authenticated user's appearance preferences once, so the first
// paint respects them without a flash of default styling.
$_authUser = auth()->user();
$_userAccent = $_authUser?->accent_colour;
$_userFontSize = $_authUser?->font_size ?? 14;
$_userDensity = $_authUser?->sidebar_density ?? 'comfortable';
$_userReduceMotion = (bool) ($_authUser?->reduce_motion ?? false);
// Theme resolution order: user's saved preference, then the appearance
// cookie (set when the user toggled the theme but the save is in-flight or
// they're not signed in yet), then the controller-provided $appearance, then
// 'system'. Without the cookie fallback, a user who toggled to light but
// whose users.theme is still null sees a dark flash on every page load.
$_cookieTheme = request()->cookie('appearance');
$_cookieTheme = in_array($_cookieTheme, ['light', 'dark', 'system'], true) ? $_cookieTheme : null;
$_userTheme = $_authUser?->theme ?? $_cookieTheme ?? ($appearance ?? 'system');

$_htmlClasses = [];
if ($_userTheme === 'dark') {
    $_htmlClasses[] = 'dark';
}
if ($_userReduceMotion) {
    $_htmlClasses[] = 'reduce-motion';
}
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      @class($_htmlClasses)
      data-density="{{ in_array($_userDensity, ['comfortable', 'compact'], true) ? $_userDensity : 'comfortable' }}"
      style="--base-font-size: {{ (int) max(10, min(24, $_userFontSize)) }}px;{{ $_userAccent ? ' --primary: ' . e($_userAccent) . ';' : '' }}">

<head>
    <meta charset="utf-8">
    {{-- viewport-fit=cover lets env(safe-area-inset-*) resolve on iOS so the
         frontline shell, bottom nav, and sticky action bars respect the
         home-indicator and notch insets. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    {{-- Theme colour matches the app background so the mobile browser chrome
         (and iOS status bar in standalone mode) blends into the shell. --}}
    <meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0a0a0a" media="(prefers-color-scheme: dark)">
    {{-- Tighten standalone/home-screen presentation on iOS. No full PWA work
         in this PR — these are just light, low-risk shell hints. --}}
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="mobile-web-app-capable" content="yes">

    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- Inline script to detect system dark mode preference and apply it immediately.
         Carries the CSP nonce so it runs under script-src 'self' 'nonce-…' without
         needing 'unsafe-inline'. See App\Http\Middleware\AddContentSecurityPolicy. --}}
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        (function() {
            const appearance = '{{ $_userTheme }}';

            if (appearance === 'system') {
                const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                if (prefersDark) {
                    document.documentElement.classList.add('dark');
                }
            }
        })();
    </script>

    {{-- Inline style to set the HTML background color based on our theme in app.css --}}
    <style>
        html {
            background-color: oklch(var(--background));
        }

        html.dark {
            background-color: oklch(var(--background));
        }
    </style>

    {{-- Organisation theme overrides (admin-configurable) --}}
    @php
    $theme = $page['props']['theme'] ?? ['light' => [], 'dark' => []];
    $light = is_array($theme['light'] ?? null) ? $theme['light'] : [];
    $dark = is_array($theme['dark'] ?? null) ? $theme['dark'] : [];

    $allowedVars = [
    '--primary', '--primary-foreground',
    '--secondary', '--secondary-foreground',
    '--accent', '--accent-foreground',
    '--background', '--foreground',
    '--card', '--card-foreground',
    '--popover', '--popover-foreground',
    '--border', '--input', '--ring',
    '--sidebar', '--sidebar-foreground',
    '--sidebar-primary', '--sidebar-primary-foreground',
    '--sidebar-accent', '--sidebar-accent-foreground',
    '--sidebar-border', '--sidebar-ring',
    '--radius',
    ];

    $toCss = function (array $vars) use ($allowedVars) {
    $out = '';
    foreach ($vars as $k => $v) {
    if (!is_string($k) || !in_array($k, $allowedVars, true)) {
    continue;
    }
    if (!is_string($v)) {
    continue;
    }
    $val = trim($v);
    if ($val === '') {
    continue;
    }
    // Keep it simple: rely on allowed var names and basic trimming.
    $out .= $k . ': ' . e($val) . ';';
    }
    return $out;
    };

    $lightCss = $toCss($light);
    $darkCss = $toCss($dark);
    @endphp

    @if($lightCss || $darkCss)
    <style>
        @if($lightCss) :root {
                {
                ! ! $lightCss ! !
            }
        }

        @endif @if($darkCss) .dark {
                {
                ! ! $darkCss ! !
            }
        }

        @endif
    </style>
    @endif

    <title inertia>{{ config('app.name', 'Laravel') }}</title>

    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

    {{-- FullCalendar v6 CSS is bundled into the JS packages automatically (no separate CSS needed) --}}

    @viteReactRefresh
    {{--
        Only include the main React entrypoint.
        Inertia pages are resolved via import.meta.glob() in resources/js/app.tsx.
        Including a dynamic page entry here breaks production builds because the
        entrypoints must be known at build time.
    --}}
    @vite(['resources/js/app.tsx'])
    @inertiaHead
</head>

<body class="font-sans antialiased">
    @inertia
</body>

</html>