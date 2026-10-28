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
}
