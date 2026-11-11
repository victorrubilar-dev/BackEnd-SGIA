<?php

namespace App\Providers;

use App\Models\AnnualBudget;
use App\Models\EquipmentUnit;
use App\Models\IncidentReport;
use App\Models\InvoiceIntake;
use App\Models\Loan;
use App\Models\MaintenanceDocument;
use App\Models\MaintenanceRecord;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseRequest;
use App\Models\Quotation;
use App\Models\Storeroom;
use App\Models\Student;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserChart;
use App\Observers\InventoryAuditObserver;
use App\Observers\PurchasingAuditObserver;
use App\Observers\UserAuditObserver;
use App\Policies\AnnualBudgetPolicy;
use App\Policies\EquipmentUnitPolicy;
use App\Policies\IncidentReportPolicy;
use App\Policies\InvoiceIntakePolicy;
use App\Policies\LoanPolicy;
use App\Policies\MaintenanceDocumentPolicy;
use App\Policies\MaintenanceRecordPolicy;
use App\Policies\ProductPolicy;
use App\Policies\PurchasePolicy;
use App\Policies\PurchaseRequestPolicy;
use App\Policies\QuotationPolicy;
use App\Policies\StoreroomPolicy;
use App\Policies\StudentPolicy;
use App\Policies\SupplierPolicy;
use App\Policies\UserChartPolicy;
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
        Gate::policy(PurchaseRequest::class, PurchaseRequestPolicy::class);
        Gate::policy(Supplier::class, SupplierPolicy::class);
        Gate::policy(Quotation::class, QuotationPolicy::class);
        Gate::policy(Loan::class, LoanPolicy::class);
        Gate::policy(EquipmentUnit::class, EquipmentUnitPolicy::class);
        Gate::policy(IncidentReport::class, IncidentReportPolicy::class);
        Gate::policy(AnnualBudget::class, AnnualBudgetPolicy::class);
        Gate::policy(InvoiceIntake::class, InvoiceIntakePolicy::class);
        Gate::policy(Student::class, StudentPolicy::class);
        Gate::policy(MaintenanceRecord::class, MaintenanceRecordPolicy::class);
        Gate::policy(MaintenanceDocument::class, MaintenanceDocumentPolicy::class);
        Gate::policy(UserChart::class, UserChartPolicy::class);
        Gate::policy(Storeroom::class, StoreroomPolicy::class);

        // REQ-NF-01: auditoría de eventos críticos.
        User::observe(UserAuditObserver::class);
        EquipmentUnit::observe(InventoryAuditObserver::class);
        Product::observe(InventoryAuditObserver::class);
        Purchase::observe(PurchasingAuditObserver::class);
        InvoiceIntake::observe(PurchasingAuditObserver::class);

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
