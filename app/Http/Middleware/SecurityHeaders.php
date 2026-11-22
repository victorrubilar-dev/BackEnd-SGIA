<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Auditoría de seguridad (docs/auditoria-seguridad.md, H-12): cabeceras de endurecimiento en las respuestas de la API.
 * Los archivos descargados (PDF, imágenes) no se interpretan con otro tipo ni se incrustan en otro sitio.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-site');

        return $response;
    }
}
