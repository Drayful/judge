<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0b1224">
    <title>@yield('title', 'Планшет судьи') — {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800,900&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        [x-cloak] { display: none !important; }
        html, body { overscroll-behavior: none; }
    </style>
</head>
<body class="font-sans antialiased bg-[#0b1224] text-slate-100 h-screen overflow-hidden">
    <div id="app-async-page" data-async-page class="h-screen overflow-hidden">
        @yield('content')
        @stack('body-scripts')
    </div>
</body>
</html>
