<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased overflow-x-hidden bg-slate-950 text-slate-100">
        <div class="min-h-screen bg-gradient-to-b from-slate-950 via-slate-900 to-slate-950">
            <!-- Global Top Bar -->
            @isset($header)
                <header class="sticky top-0 z-50 bg-slate-950/90 backdrop-blur border-b border-slate-800">
                    <div class="min-h-16 w-full px-4 sm:px-6 lg:px-8 py-4">
                        <div class="min-w-0 w-full text-slate-100">
                            {{ $header }}
                        </div>
                    </div>
                </header>
            @endisset

            <div class="min-h-screen md:flex">
                @include('layouts.navigation')

                <div class="flex-1 min-w-0">
                    <!-- Page Content -->
                    <main class="pb-16">
                        <div class="w-full px-4 sm:px-6 lg:px-8 py-8">
                            <div class="max-w-full overflow-x-hidden">
                                {{ $slot }}
                            </div>
                        </div>
                    </main>
                </div>
            </div>
        </div>
    </body>
</html>
