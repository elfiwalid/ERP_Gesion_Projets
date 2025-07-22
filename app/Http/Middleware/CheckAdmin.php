<?php

namespace App\Http\Middleware;

use Closure;

class CheckAdmin
{
    public function handle($request, Closure $next)
    {
        $user = $request->user();
        if (!in_array($user->role->name, ['Admin Général', 'Responsable Administratif'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return $next($request);
    }
}
