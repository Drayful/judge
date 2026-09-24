<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRole
{
    /**
     * @param  array<int, string>  ...$roles
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        if (! $user instanceof User) {
            abort(403);
        }

        if ($user->isChiefJudge()) {
            $name = $request->route()?->getName();
            $scoreRoutes = [
                'secretary.performance.confirmScore', 'secretary.performance.returnScores',
                'secretary.performance.updateJudgeScore', 'secretary.performance.setFinalScore',
                'secretary.performance.clearFinalOverride', 'supervisor.approve',
                'inquiries.store', 'inquiries.underReview', 'inquiries.decide',
                'profile.update', 'profile.destroy', 'logout',
            ];
            abort_if(in_array($name, ['secretary.queue', 'secretary.queue.ping', 'secretary.tournament.live'], true), 403);
            abort_if(! $request->isMethod('GET') && ! $request->isMethod('HEAD')
                && ! in_array($name, $scoreRoutes, true), 403);
        }

        $allowed = false;
        if ($request->routeIs('secretary.start', 'secretary.callNext')) {
            $category = $request->route('category') ?? $request->route('performance')?->category;
            $sessionId = $request->route('performance')?->stream_session_id ?? $request->input('stream_session_id');
            $state = $sessionId ? $category?->sessions()->findOrFail($sessionId) : $category;
            abort_if($state?->closed_at !== null, 422, 'Сначала откройте поток.');
        }
        foreach ($roles as $role) {
            // "Role groups" convenience tokens for routes.
            $allowed = match ($role) {
                'admin' => $user->isAdmin() || $user->role === 'admin',
                'super_admin' => $user->role === 'super_admin',
                'secretary' => $user->isSecretary() || $user->role === 'secretary',
                'judge' => $user->isAnyJudge()
                    || $user->role === 'judge'
                    || in_array($user->role, ['judge_d', 'judge_a', 'judge_e'], true),
                default => $user->role === $role,
            };

            if ($allowed) {
                break;
            }
        }

        if (! $allowed) {
            abort(403);
        }

        return $next($request);
    }
}
