<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#020617">
    <title>@yield('title', 'Планшет судьи') — {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased bg-slate-950 text-slate-100 min-h-screen">
    <x-application-logo class="pointer-events-none fixed bottom-2 right-3 z-[90] h-5 w-auto text-white opacity-60" />
    <div id="app-async-page" data-async-page class="min-h-screen bg-gradient-to-b from-slate-950 via-slate-900 to-slate-950">
        @yield('content')
        @stack('body-scripts')
    </div>
</body>
</html>
