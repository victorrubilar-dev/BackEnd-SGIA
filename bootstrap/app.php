<?php

use App\Http\Middleware\CheckRole;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn () => null);
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'role' => CheckRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Errores de la API siempre como {"message": "..."} en español, sin exponer clases internas.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'No autenticado.'], 401);
            }
        });

        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'No tienes permisos para realizar esta acción.'], 403);
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'Recurso no encontrado.'], 404);
            }
        });

        // Auditoría de seguridad (docs/auditoria-seguridad.md, B2): ninguna respuesta de la API lleva SQL, clases,
        // archivos ni trazas, aunque APP_DEBUG esté activo. El detalle queda en el log (report() no cambia).
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'Demasiadas solicitudes. Espera un momento e intenta de nuevo.'], 429, $e->getHeaders());
            }
        });

        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'Método no permitido para este recurso.'], 405, $e->getHeaders());
            }
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())
                || $e instanceof ValidationException
                || $e instanceof HttpResponseException
                || $e instanceof AuthenticationException) {
                return null;
            }

            // abort(4xx, 'mensaje') del código propio: se conserva el mensaje, sin los campos de depuración.
            if ($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500) {
                return response()->json([
                    'message' => $e->getMessage() !== '' ? $e->getMessage() : 'No se pudo procesar la solicitud.',
                ], $e->getStatusCode(), $e->getHeaders());
            }

            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

            return response()->json([
                'message' => $status === 503
                    ? 'El servicio no está disponible en este momento. Intenta más tarde.'
                    : 'Ocurrió un error inesperado. Intenta nuevamente o contacta al administrador.',
            ], $status);
        });
    })->create();
