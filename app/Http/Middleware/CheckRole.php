<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     * @param  string  ...$roles
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'No autenticado.',
            ], 401);
        }

        if (! empty($roles) && ! $user->hasRole($roles)) {
            return response()->json([
                'message' => 'Acceso denegado. No tienes los permisos necesarios para realizar esta acción.',
            ], 403);
        }

        return $next($request);
    }
}
