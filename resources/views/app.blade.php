<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark'=> ($appearance ?? 'system') == 'dark'])>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- Inline script to detect system dark mode preference and apply it immediately --}}
    <script>
        (function() {
            const appearance = '{{ $appearance ?? "system" }}';

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

    @viteReactRefresh
    @vite(['resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
    @inertiaHead
</head>

<body class="font-sans antialiased">
    @inertia
</body>

</html>