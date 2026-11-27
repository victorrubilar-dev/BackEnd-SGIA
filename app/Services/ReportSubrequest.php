<?php

namespace App\Services;

use App\Models\User;
use App\Reports\ReportEmailChannel;
use Illuminate\Auth\RequestGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Route;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Subpetición interna a una ruta de reporte como si la hiciera el usuario (docs/suscripcion-reportes.md, decisión 7):
 * pasa por el mismo middleware de rol, la misma validación y el mismo `ReportExporter` que la descarga, así no hay un
 * segundo camino que pueda dar otras cifras. Al terminar deja la solicitud y la autenticación como estaban.
 */
class ReportSubrequest
{
    /**
     * @param  string  $path  Ruta sin `/api` ni consulta (`/loans/export`).
     * @param  array<string, mixed>  $query
     */
    public function get(User $user, string $path, array $query, ReportEmailChannel $channel): Response
    {
        $request = Request::create('/api'.$path, 'GET', $query, server: ['HTTP_ACCEPT' => 'application/json']);
        $request->attributes->set(ReportEmailChannel::ATTRIBUTE, $channel);

        // Copia recién leída: rol, pañol y estado de ahora, sin relaciones ni cambios del modelo de quien llama.
        $actor = User::query()->findOrFail($user->id);

        $previousRequest = app('request');
        $driver = Auth::getDefaultDriver();
        $guard = Auth::guard('sanctum');
        if (! $guard instanceof RequestGuard) {
            throw new LogicException('El guard «sanctum» no es el esperado.');
        }
        $previousUser = $guard->hasUser() ? $guard->user() : null;

        try {
            $this->bind($request);
            $guard->setUser($actor);

            return Route::dispatch($request);
        } finally {
            $this->bind($previousRequest);
            $previousUser ? $guard->setUser($previousUser) : $guard->forgetUser();
            Auth::shouldUse($driver);
        }
    }

    private function bind(Request $request): void
    {
        app()->instance('request', $request);
        Facade::clearResolvedInstance('request');
    }
}
