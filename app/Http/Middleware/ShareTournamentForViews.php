<?php

namespace App\Http\Middleware;

use App\Models\Category;
use App\Models\Tournament;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Делает $tournament и $tr доступными во всех Blade-фрагментах запроса (включая слоты x-app-layout).
 */
class ShareTournamentForViews
{
    public function handle(Request $request, Closure $next): Response
    {
        $routeTournament = $request->route('tournament');
        if ($routeTournament instanceof Tournament) {
            View::share('tournament', $routeTournament);
            View::share('tr', $routeTournament);
        }

        $category = $request->route('category');
        if ($category instanceof Category) {
            $category->loadMissing('tournament');
            View::share('tournament', $category->tournament);
            View::share('tr', $category->tournament);
        }

        return $next($request);
    }
}
