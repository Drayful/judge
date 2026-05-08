<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Judge') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased text-gray-900">
    <div class="min-h-screen bg-gradient-to-b from-gray-50 via-white to-gray-50">
        <div class="w-full px-4 sm:px-6 lg:px-8 py-10">
            <div class="flex items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <x-application-logo class="h-9 w-auto fill-current text-gray-800" />
                    <div class="leading-tight">
                        <div class="font-semibold text-gray-900">{{ config('app.name', 'Judge') }}</div>
                        <div class="text-sm text-gray-500">Веб‑судейство и табло</div>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    @auth
                        <a href="{{ route('dashboard') }}" class="inline-flex items-center px-3 py-2 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-sm font-medium">
                            Панель
                        </a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="inline-flex items-center px-3 py-2 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-sm font-medium">
                                Выйти
                            </button>
                        </form>
                    @else
                        <a href="{{ route('login') }}" class="inline-flex items-center px-3 py-2 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-sm font-medium">
                            Войти
                        </a>
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="inline-flex items-center px-3 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-sm font-medium text-white">
                                Регистрация
                            </a>
                        @endif
                    @endauth
                </div>
            </div>

            <div class="mt-8 grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2">
                    <x-card>
                        <div class="text-xs font-semibold tracking-wide text-gray-500 uppercase">Система</div>
                        <div class="mt-2 text-2xl font-semibold text-gray-900">
                            Судейство по выступлениям (D/A/E + штрафы) и живое табло
                        </div>
                        <div class="mt-2 text-gray-600">
                            Для спортсменок — загрузка музыки под каждое выступление. Для секретарей — очередь и скачивание. Для судей — ввод оценок по панелям.
                        </div>

                        <div class="mt-5 flex flex-wrap gap-2">
                            <a href="{{ route('scoreboard.category', 1) }}" class="inline-flex items-center px-3 py-2 rounded-lg bg-gray-900 hover:bg-gray-800 text-white text-sm font-medium">
                                Открыть табло
                            </a>
                            @auth
                                <a href="{{ route('dashboard') }}" class="inline-flex items-center px-3 py-2 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-sm font-medium">
                                    Перейти в панель
                                </a>
                            @else
                                <a href="{{ route('login') }}" class="inline-flex items-center px-3 py-2 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 text-sm font-medium">
                                    Войти и начать
                                </a>
                            @endauth
                        </div>
                    </x-card>
                </div>

                <div class="lg:col-span-1">
                    <x-card>
                        <div class="text-sm font-semibold text-gray-900">Роли</div>
                        <div class="mt-3 flex flex-col gap-2 text-sm text-gray-700">
                            <div class="flex items-center justify-between gap-3">
                                <span>Спортсменка</span>
                                <x-badge tone="gray">музыка</x-badge>
                            </div>
                            <div class="flex items-center justify-between gap-3">
                                <span>Секретарь</span>
                                <x-badge tone="gray">очередь</x-badge>
                            </div>
                            <div class="flex items-center justify-between gap-3">
                                <span>Судьи</span>
                                <x-badge tone="gray">оценки</x-badge>
                            </div>
                            <div class="flex items-center justify-between gap-3">
                                <span>Супервизор</span>
                                <x-badge tone="gray">approve/publish</x-badge>
                            </div>
                        </div>
                    </x-card>
                </div>
            </div>

            <div class="mt-8 text-sm text-gray-500">
                Демодоступы смотри в файле <span class="font-mono">DEMO_CREDENTIALS.txt</span>.
            </div>
        </div>
    </div>
</body>
</html>
