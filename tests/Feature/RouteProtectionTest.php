<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RouteProtectionTest extends TestCase
{
    public function test_every_api_route_except_login_rejects_guests(): void
    {
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/') || $route->uri() === 'api/login') {
                continue;
            }

            $uri = '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri());

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $this->json($method, $uri)->assertUnauthorized("{$method} {$uri}");
                $checked++;
            }
        }

        $this->assertGreaterThan(50, $checked);
    }

    /**
     * Toda ruta nueva debe declarar `role:` o estar aquí a propósito
     * (accesible a cualquier autenticado; el recurso se protege con Policy).
     */
    public function test_every_api_route_declares_roles_or_is_explicitly_shared(): void
    {
        $shared = [
            'api/me', 'api/password', 'api/logout',
            'api/logout-all', 'api/tokens', 'api/tokens/{tokenId}', 'api/tokens/revoke-others',
            'api/locations', 'api/locations/{location}', 'api/cajones', 'api/cajones/{cajon}',
            'api/equipment/{product}/technical-sheet', 'api/equipment/{product}/reports',
            'api/incident-reports/{incidentReport}/attachment',
            'api/products', 'api/products/{product}', 'api/products/{product}/location',
            'api/products/{product}/barcode', 'api/products/{product}/status', 'api/products/{product}/condition',
        ];

        $unprotected = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/') || $uri === 'api/login' || in_array($uri, $shared, true)) {
                continue;
            }

            $hasRole = collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'role:'));

            if (! $hasRole) {
                $unprotected[] = implode('|', $route->methods()).' '.$uri;
            }
        }

        $this->assertSame([], $unprotected, 'Rutas sin middleware role: ni en la lista de compartidas.');
    }
}
