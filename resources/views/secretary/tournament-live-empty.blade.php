<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <a class="text-sm text-emerald-400 hover:text-emerald-300" href="{{ route('secretary.tournament', $tournament) }}">← {{ $tournament->name }}</a>
        </div>
    </x-slot>

    <div class="py-10 max-w-xl mx-auto px-4">
        <x-flash />
        <x-card>
            <h1 class="text-lg font-semibold text-slate-100">Live — Секретарь</h1>
            <p class="mt-2 text-sm text-slate-400">
                В этом турнире пока нет потоков (категорий). Импортируйте стартовый протокол Excel на странице турнира — каждый поток из файла появится здесь в виде отдельного потока в списке.
            </p>
            <div class="mt-6">
                <a href="{{ route('secretary.tournament', $tournament) }}" class="text-emerald-400 hover:text-emerald-300 font-medium">
                    Перейти к турниру →
                </a>
            </div>
        </x-card>
    </div>
</x-app-layout>
