<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#07090d">
    <title>@yield('title', 'Планшет судьи') — {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800,900&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        [x-cloak] { display: none !important; }
        html, body { overscroll-behavior: none; }

        /*
         * The judging workspace is designed around a 1366x768 landscape canvas.
         * Scale every rem-based control from both viewport dimensions so the same
         * layout fits compact 8.7" tablets and grows naturally on larger screens.
         * The 9px floor still supports the common 800x480 CSS viewport used by
         * compact Android tablets, while the upper bound prevents oversized text
         * on external 4K displays.
         */
        html {
            font-size: clamp(9px, min(1.1713vw, 2.0833vh), 22px);
        }

        @supports (height: 100dvh) {
            html {
                font-size: clamp(9px, min(1.1713vw, 2.0833dvh), 22px);
            }
        }

        :root {
            --judge-accent: 45 212 191;
            --judge-accent-soft: 20 184 166;
            --judge-warning: 251 191 36;
            --judge-danger: 251 113 133;
        }

        body {
            background:
                radial-gradient(circle at 12% -10%, rgb(var(--judge-accent) / .13), transparent 34%),
                radial-gradient(circle at 92% 110%, rgb(56 189 248 / .08), transparent 35%),
                #07090d;
        }

        .judge-console {
            --judge-accent: 45 212 191;
            --judge-accent-soft: 20 184 166;
            isolation: isolate;
            background:
                linear-gradient(rgb(255 255 255 / .022) 1px, transparent 1px),
                linear-gradient(90deg, rgb(255 255 255 / .022) 1px, transparent 1px),
                radial-gradient(circle at 18% 0%, rgb(var(--judge-accent) / .11), transparent 31%),
                #07090d;
            background-size: 42px 42px, 42px 42px, auto, auto;
        }

        .judge-console[data-panel="a"] {
            --judge-accent: 167 139 250;
            --judge-accent-soft: 124 58 237;
        }

        .judge-console[data-panel="e"] {
            --judge-accent: 56 189 248;
            --judge-accent-soft: 2 132 199;
        }

        .judge-console[data-panel="penalty"] {
            --judge-accent: 251 191 36;
            --judge-accent-soft: 217 119 6;
        }

        .judge-shell {
            position: relative;
            max-width: none !important;
            padding-top: max(.625rem, env(safe-area-inset-top)) !important;
            padding-right: max(.625rem, env(safe-area-inset-right)) !important;
            padding-bottom: max(.625rem, env(safe-area-inset-bottom)) !important;
            padding-left: max(.625rem, env(safe-area-inset-left)) !important;
        }

        .judge-console,
        #app-async-page,
        body {
            height: 100vh;
            max-height: 100vh;
        }

        @supports (height: 100dvh) {
            .judge-console,
            #app-async-page,
            body {
                height: 100dvh !important;
                max-height: 100dvh;
            }
        }

        .judge-shell::before {
            position: absolute;
            inset: 0 15% auto;
            height: 1px;
            content: '';
            background: linear-gradient(90deg, transparent, rgb(var(--judge-accent) / .65), transparent);
            pointer-events: none;
        }

        .judge-topbar {
            position: relative;
            overflow: hidden;
            border: 1px solid rgb(255 255 255 / .09);
            border-radius: 12px;
            background: linear-gradient(115deg, rgb(20 24 31 / .94), rgb(11 14 19 / .9));
            box-shadow: 0 14px 36px rgb(0 0 0 / .28), inset 0 1px rgb(255 255 255 / .05);
        }

        .judge-topbar::after {
            position: absolute;
            inset: 0 auto 0 0;
            width: 3px;
            content: '';
            background: rgb(var(--judge-accent));
            box-shadow: 0 0 22px rgb(var(--judge-accent) / .65);
        }

        .judge-back-button,
        .judge-meta-chip {
            border: 1px solid rgb(255 255 255 / .08);
            background: rgb(255 255 255 / .035);
            box-shadow: inset 0 1px rgb(255 255 255 / .04);
            color: rgb(203 213 225);
        }

        .judge-slot-chip {
            border-color: rgb(var(--judge-accent) / .38);
            background: rgb(var(--judge-accent) / .1);
            color: rgb(var(--judge-accent));
        }

        .judge-workspace {
            position: relative;
        }

        .judge-workspace button {
            position: relative;
            overflow: hidden;
            border-radius: 9px;
            border-color: rgb(100 116 139 / .44) !important;
            background-color: rgb(20 29 47) !important;
            background-image: linear-gradient(145deg, rgb(var(--judge-accent) / .13), transparent 58%, rgb(0 0 0 / .18)) !important;
            color: rgb(248 250 252) !important;
            box-shadow: 0 7px 18px rgb(0 0 0 / .2), inset 0 1px rgb(255 255 255 / .07);
            transition: transform .12s ease, filter .12s ease, border-color .12s ease, box-shadow .12s ease;
        }

        .judge-workspace button:hover {
            border-color: rgb(var(--judge-accent) / .48);
            background-color: rgb(30 41 59) !important;
            box-shadow: 0 9px 22px rgb(0 0 0 / .26), inset 0 1px rgb(255 255 255 / .1);
        }

        .judge-workspace button:active {
            transform: translateY(1px) scale(.985);
            box-shadow: 0 3px 10px rgb(0 0 0 / .28), inset 0 1px 6px rgb(0 0 0 / .22);
        }

        .judge-score-stage {
            position: relative;
            overflow: hidden;
            border-color: rgb(var(--judge-accent) / .28) !important;
            background:
                radial-gradient(circle at 50% 5%, rgb(var(--judge-accent) / .12), transparent 48%),
                linear-gradient(160deg, rgb(20 24 31 / .97), rgb(9 12 17 / .97)) !important;
            color: rgb(248 250 252);
            box-shadow: 0 18px 44px rgb(0 0 0 / .28), inset 0 1px rgb(255 255 255 / .05);
        }

        .judge-score-stage::before {
            position: absolute;
            inset: 0 24% auto;
            height: 2px;
            content: '';
            background: linear-gradient(90deg, transparent, rgb(var(--judge-accent)), transparent);
            opacity: .8;
        }

        .judge-submit-button {
            border-color: rgb(var(--judge-accent) / .45) !important;
            background-color: rgb(var(--judge-accent-soft)) !important;
            background-image: linear-gradient(100deg, rgb(var(--judge-accent-soft)), rgb(var(--judge-accent) / .72)) !important;
            color: white !important;
            box-shadow: 0 10px 24px rgb(var(--judge-accent) / .2), inset 0 1px rgb(255 255 255 / .2) !important;
        }

        .judge-state-card,
        .judge-numpad {
            border: 1px solid rgb(var(--judge-accent) / .28);
            background:
                radial-gradient(circle at 50% 0%, rgb(var(--judge-accent) / .12), transparent 48%),
                linear-gradient(160deg, rgb(20 24 31 / .98), rgb(8 11 16 / .98));
            color: rgb(248 250 252);
            box-shadow: 0 30px 80px rgb(0 0 0 / .48), inset 0 1px rgb(255 255 255 / .06);
        }

        .judge-numpad-key {
            border-color: rgb(100 116 139 / .45) !important;
            background-color: rgb(28 37 71) !important;
            color: white !important;
        }

        .judge-numpad-apply {
            border-color: rgb(var(--judge-accent) / .45) !important;
            background: rgb(var(--judge-accent-soft)) !important;
            color: white !important;
        }

        .judge-workspace > div:not(.absolute):not(:has(.judge-score-stage)) {
            padding-top: clamp(2.5rem, 7vh, 4.75rem);
        }

        .judge-workspace > div:has(> .judge-score-stage) > .judge-score-stage {
            flex: 0 0 auto;
            min-height: clamp(13rem, 37vh, 20rem);
        }

        .judge-workspace > div:has(> .judge-score-stage) > .judge-submit-button {
            margin-top: auto;
        }

        .judge-workspace button[class*="bg-rose"],
        .judge-workspace button[class*="#6f1d2e"],
        .judge-workspace button[class*="#5a1d28"],
        .judge-workspace button[class*="#7a1f2e"],
        .judge-workspace button[class*="#962638"] {
            border-color: rgb(244 63 94 / .4) !important;
            background-color: rgb(76 18 36) !important;
            background-image: linear-gradient(145deg, rgb(251 113 133 / .18), transparent 62%) !important;
            color: rgb(255 228 230) !important;
        }

        .judge-workspace button[class*="bg-emerald"] {
            border-color: rgb(16 185 129 / .42) !important;
            background-color: rgb(6 78 59) !important;
            color: rgb(209 250 229) !important;
        }

        /* Tailwind arbitrary pixel utilities do not follow the root scale. */
        .judge-console .text-\[9px\] { font-size: .5625rem !important; }
        .judge-console .text-\[10px\] { font-size: .625rem !important; }
        .judge-console .text-\[11px\] { font-size: .6875rem !important; }
        .judge-console .min-w-\[100px\] { min-width: 6.25rem !important; }
        .judge-console .min-h-\[20px\] { min-height: 1.25rem !important; }
        .judge-console .w-\[380px\] { width: min(23.75rem, calc(100vw - 2rem)) !important; }

        @media (prefers-reduced-motion: reduce) {
            .judge-workspace button { transition: none; }
        }
    </style>
</head>
<body class="font-sans antialiased bg-[#07090d] text-slate-100 h-screen overflow-hidden">
    <div id="app-async-page" data-async-page class="h-screen overflow-hidden">
        @yield('content')
        @stack('body-scripts')
    </div>
</body>
</html>
