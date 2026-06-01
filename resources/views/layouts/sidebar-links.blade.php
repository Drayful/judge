@php
    $user = Auth::user();
    $role = $role ?? ($user?->role ?? null);
    $canSecretary = $user ? ($user->isSecretary() || $user->isAdmin()) : false;
    $canJudge = $user ? ($user->isAnyJudge() || $user->isAdmin()) : false;
@endphp

<nav class="px-3 py-4 space-y-1">
    <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? $linkActive : $linkIdle }} {{ $linkBase }}">
        Главная
    </a>

    @if($role === 'athlete')
        <a href="{{ route('athlete.music') }}" class="{{ request()->routeIs('athlete.*') ? $linkActive : $linkIdle }} {{ $linkBase }}">
            Музыка
        </a>
    @endif

    @if($canSecretary)
        <a href="{{ route('secretary.tournaments') }}" class="{{ (request()->routeIs('secretary.tournaments*') || request()->routeIs('secretary.tournament*')) ? $linkActive : $linkIdle }} {{ $linkBase }}">
            Турниры
        </a>
        <a href="{{ route('secretary.categories') }}" class="{{ (request()->routeIs('secretary.categories') || request()->routeIs('secretary.queue') || request()->routeIs('secretary.tournament.live')) ? $linkActive : $linkIdle }} {{ $linkBase }}">
            Категории / Очередь
        </a>
        <a href="{{ route('secretary.athletes') }}" class="{{ request()->routeIs('secretary.athletes*') ? $linkActive : $linkIdle }} {{ $linkBase }}">
            Атлеты
        </a>
    @endif

    @if($canJudge)
        <a href="{{ route('judge.tournaments') }}" class="{{ request()->routeIs('judge.*') ? $linkActive : $linkIdle }} {{ $linkBase }}">
            Судейство
        </a>
    @endif

    <a href="{{ route('scoreboard.index') }}" class="{{ request()->routeIs('scoreboard.*') ? $linkActive : $linkIdle }} {{ $linkBase }}">
        Табло
    </a>
</nav>

