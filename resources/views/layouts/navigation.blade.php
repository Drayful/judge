<div x-data="{ open: false }">
    @php
        $role = Auth::user()->role ?? null;
        $linkBase = 'flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium transition';
        $linkIdle = 'text-slate-400 hover:bg-slate-800/80 hover:text-white';
        $linkActive = 'bg-emerald-600/90 text-white shadow-sm';
    @endphp

    <!-- Mobile: floating menu button -->
    <button
        type="button"
        @click="open = true"
        class="md:hidden fixed top-3 left-3 z-[70] inline-flex items-center justify-center
               h-10 w-10 rounded-xl bg-slate-900/95 backdrop-blur border border-slate-700 shadow-sm
               text-slate-200 hover:text-white hover:bg-slate-800"
        aria-label="Открыть меню"
    >
        <svg class="h-5 w-5" stroke="currentColor" fill="none" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
        </svg>
    </button>

    <!-- Mobile drawer backdrop -->
    <div x-show="open" x-transition.opacity class="md:hidden fixed inset-0 z-[65] bg-black/30" @click="open = false"></div>

    <!-- Desktop sidebar (always below top bar) -->
    <aside class="hidden md:flex w-72 shrink-0 sticky top-16 mt-2 z-30 h-[calc(100dvh-4rem-0.5rem)] border border-slate-800 rounded-2xl bg-slate-900/90 shadow-lg shadow-slate-950/40">
        <div class="flex flex-col w-full">
            <div class="h-16 px-4 flex items-center border-b border-slate-800">
                <a href="{{ route('dashboard') }}" class="flex items-center gap-2">
                    <x-application-logo class="block h-7 w-auto text-white" />
                </a>
            </div>

            <div class="flex-1 overflow-y-auto">
                @include('layouts.sidebar-links', ['role' => $role, 'linkBase' => $linkBase, 'linkIdle' => $linkIdle, 'linkActive' => $linkActive])
            </div>

            <div class="px-3 pb-4 border-t border-slate-800 bg-slate-900/95">
                <div class="px-3 py-3">
                    <div class="text-sm font-medium text-slate-100 truncate">{{ Auth::user()->name }}</div>
                    <div class="text-xs text-slate-400 truncate">{{ Auth::user()->email }}</div>
                    <div class="mt-1 text-xs text-slate-500 uppercase tracking-wide">{{ Auth::user()->role }}</div>
                </div>

                <div class="space-y-1">
                    <a href="{{ route('profile.edit') }}" class="{{ $linkIdle }} {{ $linkBase }}">
                        Профиль
                    </a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="w-full text-left {{ $linkIdle }} {{ $linkBase }}">
                            Выйти
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </aside>

    <!-- Mobile drawer -->
    <aside
        class="md:hidden w-72 border-r border-slate-800 bg-slate-900/98 backdrop-blur
               fixed top-0 left-0 z-[66] h-dvh shadow-xl
               transform transition-transform duration-200 ease-out"
        :class="open ? 'translate-x-0' : '-translate-x-full'"
        @keydown.escape.window="open = false"
    >
        <div class="h-dvh flex flex-col">
            <div class="h-14 px-4 flex items-center justify-between border-b border-slate-800">
                <a href="{{ route('dashboard') }}" class="flex items-center gap-2">
                    <x-application-logo class="block h-6 w-auto text-white" />
                </a>
                <button class="inline-flex items-center justify-center p-2 rounded-md text-slate-400 hover:text-white hover:bg-slate-800" @click="open = false">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <div class="flex-1 overflow-y-auto">
                @include('layouts.sidebar-links', ['role' => $role, 'linkBase' => $linkBase, 'linkIdle' => $linkIdle, 'linkActive' => $linkActive])
            </div>

            <div class="px-3 pb-4 border-t border-slate-800 bg-slate-900/98">
                <div class="px-3 py-3">
                    <div class="text-sm font-medium text-slate-100 truncate">{{ Auth::user()->name }}</div>
                    <div class="text-xs text-slate-400 truncate">{{ Auth::user()->email }}</div>
                    <div class="mt-1 text-xs text-slate-500 uppercase tracking-wide">{{ Auth::user()->role }}</div>
                </div>

                <div class="space-y-1">
                    <a href="{{ route('profile.edit') }}" class="{{ $linkIdle }} {{ $linkBase }}">
                        Профиль
                    </a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="w-full text-left {{ $linkIdle }} {{ $linkBase }}">
                            Выйти
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </aside>
</div>
