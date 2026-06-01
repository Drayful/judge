<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Judge') }} · Веб-судейство</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased bg-slate-950 text-slate-100 min-h-screen">
    <div class="relative isolate min-h-screen overflow-hidden">
        {{-- Декоративные градиенты на тёмном фоне --}}
        <div aria-hidden="true" class="pointer-events-none absolute inset-0 -z-10">
            <div class="absolute -top-32 -left-24 w-[28rem] h-[28rem] rounded-full bg-emerald-500/10 blur-3xl"></div>
            <div class="absolute top-40 -right-32 w-[32rem] h-[32rem] rounded-full bg-violet-500/10 blur-3xl"></div>
            <div class="absolute bottom-0 left-1/3 w-[24rem] h-[24rem] rounded-full bg-sky-500/10 blur-3xl"></div>
        </div>

        <div class="w-full max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
            {{-- Шапка --}}
            <header class="flex items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <x-application-logo class="h-9 w-auto fill-current text-emerald-400" />
                    <div class="leading-tight">
                        <div class="font-semibold text-slate-100">{{ config('app.name', 'Judge') }}</div>
                        <div class="text-sm text-slate-400">Веб-судейство и живое табло</div>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    @auth
                        <a href="{{ route('dashboard') }}" class="inline-flex items-center px-3 py-2 rounded-lg border border-slate-700 bg-slate-900/60 hover:bg-slate-800 text-sm font-medium text-slate-200 transition">
                            Панель
                        </a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="inline-flex items-center px-3 py-2 rounded-lg border border-slate-700 bg-slate-900/60 hover:bg-slate-800 text-sm font-medium text-slate-200 transition">
                                Выйти
                            </button>
                        </form>
                    @else
                        <a href="{{ route('login') }}" class="inline-flex items-center px-3 py-2 rounded-lg border border-slate-700 bg-slate-900/60 hover:bg-slate-800 text-sm font-medium text-slate-200 transition">
                            Войти
                        </a>
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="inline-flex items-center px-3 py-2 rounded-lg bg-emerald-500 hover:bg-emerald-400 text-sm font-medium text-white shadow-lg shadow-emerald-500/20 transition">
                                Регистрация
                            </a>
                        @endif
                    @endauth
                </div>
            </header>

            {{-- Hero --}}
            <section class="mt-14 grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2 relative overflow-hidden rounded-2xl border border-slate-800 bg-slate-900/60 backdrop-blur p-8">
                    <div class="inline-flex items-center gap-2 text-[11px] uppercase tracking-widest text-emerald-300/90">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                        Live · D / A / E · Штрафы
                    </div>
                    <h1 class="mt-3 text-3xl sm:text-4xl font-semibold text-white leading-tight">
                        Судейство выступлений и&nbsp;живое табло
                        <span class="text-emerald-400">в&nbsp;одном окне</span>
                    </h1>
                    <p class="mt-3 text-slate-300/90 max-w-2xl">
                        Спортсменки загружают музыку под каждый выход. Секретарь ведёт очередь и состав
                        бригады. Судьи ставят оценки с&nbsp;планшета. Зрители следят за&nbsp;табло
                        в&nbsp;реальном времени.
                    </p>

                    <div class="mt-6 flex flex-wrap gap-2">
                        <a href="{{ route('scoreboard.index') }}" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg bg-emerald-500 hover:bg-emerald-400 text-white text-sm font-semibold shadow-lg shadow-emerald-500/20 transition">
                            Открыть табло →
                        </a>
                        @auth
                            <a href="{{ route('dashboard') }}" class="inline-flex items-center px-4 py-2.5 rounded-lg border border-slate-700 bg-slate-900/60 hover:bg-slate-800 text-sm font-medium text-slate-200 transition">
                                В кабинет
                            </a>
                        @else
                            <a href="{{ route('login') }}" class="inline-flex items-center px-4 py-2.5 rounded-lg border border-slate-700 bg-slate-900/60 hover:bg-slate-800 text-sm font-medium text-slate-200 transition">
                                Войти и начать
                            </a>
                        @endauth
                    </div>
                </div>

                <div class="lg:col-span-1">
                    <div class="rounded-2xl border border-slate-800 bg-slate-900/60 backdrop-blur p-6 h-full">
                        <div class="text-sm font-semibold text-slate-100">Роли в системе</div>
                        <div class="mt-4 flex flex-col gap-3 text-sm text-slate-300">
                            <div class="flex items-center justify-between gap-3">
                                <span>Спортсменка</span>
                                <x-badge tone="blue">музыка</x-badge>
                            </div>
                            <div class="flex items-center justify-between gap-3">
                                <span>Секретарь</span>
                                <x-badge tone="violet">очередь</x-badge>
                            </div>
                            <div class="flex items-center justify-between gap-3">
                                <span>Судьи (D · A · E)</span>
                                <x-badge tone="green">оценки</x-badge>
                            </div>
                            <div class="flex items-center justify-between gap-3">
                                <span>Линия · Время · Музыка</span>
                                <x-badge tone="amber">штрафы</x-badge>
                            </div>
                            <div class="flex items-center justify-between gap-3">
                                <span>Супервизор</span>
                                <x-badge tone="gray">approve / publish</x-badge>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            {{-- Карточки фич --}}
            <section class="mt-8 grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="rounded-xl border border-slate-800 bg-slate-900/50 p-5">
                    <div class="text-emerald-400 text-2xl">★</div>
                    <div class="mt-2 font-semibold text-slate-100">Живая очередь</div>
                    <p class="mt-1 text-sm text-slate-400">Drag-and-drop порядка выступлений, авто-вызов следующего, мгновенный отклик планшетов.</p>
                </div>
                <div class="rounded-xl border border-slate-800 bg-slate-900/50 p-5">
                    <div class="text-emerald-400 text-2xl">✎</div>
                    <div class="mt-2 font-semibold text-slate-100">Планшет судьи</div>
                    <p class="mt-1 text-sm text-slate-400">D / A / E панели с историей нажатий, лимитами категорий, отменой и numpad.</p>
                </div>
                <div class="rounded-xl border border-slate-800 bg-slate-900/50 p-5">
                    <div class="text-emerald-400 text-2xl">★</div>
                    <div class="mt-2 font-semibold text-slate-100">Табло Live</div>
                    <p class="mt-1 text-sm text-slate-400">Авто-обновление мест и итогов без перезагрузки, статусы запросов и публикации.</p>
                </div>
            </section>

            <footer class="mt-10 text-xs text-slate-500">
                Демо-доступы — в файле <span class="font-mono text-slate-300">DEMO_CREDENTIALS.txt</span>.
            </footer>
        </div>
    </div>
</body>
</html>
