<?php

namespace App\Http\Middleware;

use Closure;

class CheckSuperChef
{
    public function handle($request, Closure $next)
    {
        $role = $request->user()->role->name;

        if ($role !== 'Chef de Terrain Supérieur' && $role !== 'Admin Général' && $role !== 'Responsable Administratif') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return $next($request);
    }
}
