<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Auditoría de seguridad (docs/auditoria-seguridad.md, H-09): los filtros de la API son valores simples.
 * `?status[]=a` respondía 500 ("Array to string conversion"); ahora es 422.
 */
class RejectArrayQueryParameters
{
    public function handle(Request $request, Closure $next): Response
    {
        $errors = [];

        foreach ($request->query() as $key => $value) {
            if (is_array($value)) {
                $errors[$key] = ["El parámetro «{$key}» debe ser un valor simple."];
            }
        }

        if ($errors !== []) {
            return response()->json(['message' => reset($errors)[0], 'errors' => $errors], 422);
        }

        return $next($request);
    }
}
