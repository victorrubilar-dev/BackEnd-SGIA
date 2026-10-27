<?php

namespace App\Providers;

use App\Models\Cotizacion;
use App\Models\Equipo;
use App\Models\Estante;
use App\Models\InformeNovedad;
use App\Models\Prestamo;
use App\Models\ProcesoCotizacion;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Sala;
use App\Models\Usuario;
use App\Policies\IncidentReportPolicy;
use App\Policies\LoanPolicy;
use App\Policies\ProductPolicy;
use App\Policies\PurchasePolicy;
use App\Policies\QuotationPolicy;
use App\Policies\SupplierPolicy;
use App\Policies\UserPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Route::model('location', Sala::class);
        Route::model('cajon', Estante::class);
        Route::model('supplier', Proveedor::class);
        Route::model('quotation', ProcesoCotizacion::class);
        Route::model('purchase', ProcesoCotizacion::class);
        Route::model('loan', Prestamo::class);
        Route::model('product', Producto::class);

        Gate::policy(Usuario::class, UserPolicy::class);
        Gate::policy(Producto::class, ProductPolicy::class);
        Gate::policy(Equipo::class, ProductPolicy::class);
        Gate::policy(Proveedor::class, SupplierPolicy::class);
        Gate::policy(ProcesoCotizacion::class, QuotationPolicy::class);
        Gate::policy(Cotizacion::class, QuotationPolicy::class);
        Gate::policy(Prestamo::class, LoanPolicy::class);
        Gate::policy(InformeNovedad::class, IncidentReportPolicy::class);

        // REQ-14: Dashboards (FU-06) solo para Director de Carrera y Admin.
        Gate::define('viewDashboard', function (Usuario $user) {
            return $user->activo && $user->hasRole([
                Usuario::ROLE_DIRECTOR,
                Usuario::ROLE_ADMIN,
            ]);
        });

        RateLimiter::for('login', function (Request $request) {
            $email = Str::transliterate(Str::lower((string) $request->input('email')));
            $throttleKey = $email . '|' . $request->ip();

            return Limit::perMinute(5)->by($throttleKey)->response(function (Request $request, array $headers) {
                $retryAfter = $headers['Retry-After'] ?? 60;

                return response()->json([
                    'message' => "Demasiados intentos de inicio de sesión. Por favor, intenta de nuevo en {$retryAfter} segundos.",
                ], 429, $headers);
            });
        });
    }
}
