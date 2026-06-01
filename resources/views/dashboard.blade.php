<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-semibold text-xl text-slate-100 leading-tight">
                Панель
            </h2>
            <x-badge tone="violet">{{ Auth::user()->role }}</x-badge>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="w-full px-0 space-y-4">
            <x-flash />

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <x-card>
                    <div class="text-sm text-slate-500">Вы вошли как</div>
                    <div class="mt-1 text-lg font-semibold text-slate-100">{{ Auth::user()->name }}</div>
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <x-badge tone="violet">{{ Auth::user()->role }}</x-badge>
                        <x-badge tone="gray">{{ Auth::user()->email }}</x-badge>
                    </div>
                </x-card>

                <x-card>
                    <div class="text-sm text-slate-400 mb-3">Быстрые переходы</div>
                    <div class="flex flex-col gap-2">
                        @php($role = Auth::user()->role ?? null)

                        @if($role === 'athlete')
                            <a class="text-emerald-400 hover:text-emerald-300 font-medium" href="{{ route('athlete.music') }}">Музыка</a>
                        @endif

                        @if(in_array($role, ['secretary', 'admin'], true))
                            <a class="text-emerald-400 hover:text-emerald-300 font-medium" href="{{ route('secretary.categories') }}">Секретарь · Категории</a>
                        @endif

                        @if(in_array($role, ['judge', 'admin'], true))
                            <a class="text-emerald-400 hover:text-emerald-300 font-medium" href="{{ route('judge.tournaments') }}">Судейство · Турниры</a>
                        @endif

                        <a class="text-emerald-400 hover:text-emerald-300 font-medium" href="{{ route('scoreboard.index') }}">Табло</a>
                    </div>
                </x-card>
            </div>
        </div>
    </div>
</x-app-layout>
