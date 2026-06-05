<x-scoreboard-layout>
    <div class="min-h-screen flex items-center justify-center px-6 bg-gradient-to-b from-slate-950 via-slate-950 to-slate-900">
        <div class="max-w-lg w-full text-center space-y-6 live-panel p-10">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-300 text-3xl">
                ★
            </div>
            <div>
                <div class="inline-flex items-center gap-2 text-xs uppercase tracking-[0.2em] text-emerald-400/80 mb-2">
                    <span class="inline-flex h-2 w-2 rounded-full bg-emerald-400 live-pulse"></span>
                    <span>Live · Табло</span>
                </div>
                <h1 class="text-2xl font-semibold text-white">Табло пока пустое</h1>
                <p class="text-sm text-slate-400 mt-2">
                    Опубликованных категорий нет. Зайдите позже — здесь появятся живые результаты выступлений.
                </p>
            </div>
            <div class="flex items-center justify-center gap-3">
                <a href="{{ url('/') }}" class="inline-flex items-center px-4 py-2 rounded-lg bg-emerald-500 text-white text-sm font-medium hover:bg-emerald-400 transition">
                    На главную
                </a>
                @auth
                    <a href="{{ route('dashboard') }}" class="inline-flex items-center px-4 py-2 rounded-lg bg-slate-800 text-slate-200 text-sm font-medium hover:bg-slate-700 transition border border-slate-700">
                        В кабинет
                    </a>
                @endauth
            </div>
        </div>
    </div>
</x-scoreboard-layout>
