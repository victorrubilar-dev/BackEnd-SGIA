<?php

namespace App\Providers;

use App\Models\IncidentReport;
use App\Models\Loan;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Quotation;
use App\Models\Supplier;
use App\Models\User;
use App\Policies\IncidentReportPolicy;
use App\Policies\LoanPolicy;
use App\Policies\ProductPolicy;
use App\Policies\PurchasePolicy;
use App\Policies\QuotationPolicy;
use App\Policies\SupplierPolicy;
use App\Policies\UserPolicy;
use Aws\Textract\TextractClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TextractClient::class, function () {
            $config = config('services.textract');

            return new TextractClient([
                'version' => '2018-06-27',
                'region' => $config['region'],
                'credentials' => [
                    'key' => $config['key'],
                    'secret' => $config['secret'],
                    'token' => $config['token'] ?: null,
                ],
                'http' => ['timeout' => 30, 'connect_timeout' => 5],
            ]);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Lanza excepción ante N+1 fuera de producción para detectarlos en desarrollo y tests.
        Model::preventLazyLoading(! $this->app->isProduction());

        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(Purchase::class, PurchasePolicy::class);
        Gate::policy(Supplier::class, SupplierPolicy::class);
        Gate::policy(Quotation::class, QuotationPolicy::class);
        Gate::policy(Loan::class, LoanPolicy::class);
        Gate::policy(IncidentReport::class, IncidentReportPolicy::class);

        // REQ-14: Dashboards (FU-06) solo para Director de Carrera y Admin.
        Gate::define('viewDashboard', function (User $user) {
            return $user->is_active && $user->hasRole([
                User::ROLE_DIRECTOR,
                User::ROLE_ADMIN,
            ]);
        });

        // Endpoints costosos o sensibles (cambio de contraseña, OCR, subida de archivos).
        RateLimiter::for('sensitive', fn (Request $request) => Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()));

        // El panel dispara varias consultas por carga (una por gráfico).
        RateLimiter::for('metrics', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('login', function (Request $request) {
            $email = Str::transliterate(Str::lower((string) $request->input('email')));
            $throttleKey = $email.'|'.$request->ip();

            return Limit::perMinute(5)->by($throttleKey)->response(function (Request $request, array $headers) {
                $retryAfter = $headers['Retry-After'] ?? 60;

                return response()->json([
                    'message' => "Demasiados intentos de inicio de sesión. Por favor, intenta de nuevo en {$retryAfter} segundos.",
                ], 429, $headers);
            });
        });
    }
}
