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

        if (!$user) {
            abort(401);
        }

        if (!$user instanceof User) {
            abort(403);
        }

        $allowed = false;
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

        if (!$allowed) {
            abort(403);
        }

        return $next($request);
    }
}

