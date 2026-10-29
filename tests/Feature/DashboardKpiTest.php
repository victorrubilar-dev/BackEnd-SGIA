<?php

namespace Tests\Feature;

use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\Product;
use App\Models\StockAlert;
use App\Models\User;
use App\Support\DashboardPeriod;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardKpiTest extends TestCase
{
    use DatabaseTransactions;

    private function director(): User
    {
        return User::factory()->create(['role' => User::ROLE_DIRECTOR, 'is_active' => true]);
    }

    private function loan(Product $product, int $qty, array $attributes = []): Loan
    {
        $loan = Loan::factory()->processed()->create($attributes + ['loan_date' => today()->toDateString(), 'due_date' => today()->toDateString()]);
        LoanItem::create(['loan_id' => $loan->id, 'product_id' => $product->id, 'name' => $product->name, 'quantity' => $qty]);

        return $loan;
    }

    public function test_kpis_report_assets_loans_alerts_and_rates(): void
    {
        // Línea base: la BD de pruebas puede tener datos previos, se compara por diferencia.
        Sanctum::actingAs($this->director());
        $before = $this->getJson('/api/dashboard/kpis')->assertOk()->json('data');

        $product = Product::factory()->create(['quantity' => 20, 'quantity_in_repair' => 2, 'quantity_written_off' => 1]);
        $this->loan($product, 5);
        $this->loan($product, 3, ['loan_date' => today()->subDays(4)->toDateString(), 'due_date' => today()->subDays(2)->toDateString()]);
        $returned = $this->loan($product, 7);
        $returned->items()->update(['returned_at' => now()]);
        $returned->forceFill(['returned_at' => now()])->save();
        StockAlert::create(['product_id' => $product->id, 'alert_type' => StockAlert::TYPE_CRITICAL, 'current_stock' => 1, 'stock_minimo' => 5, 'message' => 'x', 'is_resolved' => false]);

        $after = $this->getJson('/api/dashboard/kpis')->assertOk()->json('data');

        $this->assertSame(20, $after['assets']['available'] - $before['assets']['available']);
        $this->assertSame(8, $after['assets']['on_loan'] - $before['assets']['on_loan']);
        $this->assertSame(2, $after['assets']['in_repair'] - $before['assets']['in_repair']);
        $this->assertSame(1, $after['assets']['written_off'] - $before['assets']['written_off']);
        $this->assertSame(2, $after['active_loans'] - $before['active_loans']);
        $this->assertSame(1, $after['overdue_loans'] - $before['overdue_loans']);
        $this->assertSame(1, $after['unresolved_alerts'] - $before['unresolved_alerts']);
        $this->assertSame(1, $after['critical_alerts'] - $before['critical_alerts']);
        $this->assertSame(15, $after['units_lent'] - $before['units_lent']);
        $this->assertIsFloat($after['rotation_rate'] + 0.0);
        $this->assertSame(today()->subDays(29)->toDateString(), $after['period']['from']);
    }

    public function test_period_filters_the_rankings(): void
    {
        $product = Product::factory()->create();
        $this->loan($product, 4, ['loan_date' => '2025-03-10', 'due_date' => '2025-03-10']);
        $this->loan($product, 9, ['loan_date' => '2026-09-10', 'due_date' => '2026-09-10']);

        Sanctum::actingAs($this->director());

        $units = fn (string $query) => collect($this->getJson("/api/dashboard/top-supplies?limit=50&{$query}")->assertOk()->json('data'))
            ->firstWhere('product_id', $product->id)['units_lent'] ?? 0;

        $this->assertSame(13, $units(''));
        $this->assertSame(4, $units('period=2025'));
        $this->assertSame(9, $units('period=2026-2'));
        $this->assertSame(0, $units('period=2026-1'));
        $this->assertSame(9, $units('from=2026-09-01&to=2026-09-30'));
        $this->assertSame(9, $units('since=2026-01-01'));
    }

    public function test_invalid_period_is_rejected_and_loans_by_teacher_works(): void
    {
        Sanctum::actingAs($this->director());

        $this->getJson('/api/dashboard/kpis?period=ayer')->assertUnprocessable()->assertJsonValidationErrors('period');
        $this->getJson('/api/dashboard/top-products?from=2026-02-01&to=2026-01-01')->assertUnprocessable();

        $product = Product::factory()->create();
        $loan = $this->loan($product, 2, ['subject' => 'Redes Avanzadas']);

        $row = collect($this->getJson('/api/dashboard/loans-by-teacher?limit=50')->assertOk()->json('data'))
            ->firstWhere('subject', 'Redes Avanzadas');

        $this->assertSame($loan->requested_by, $row['user_id']);
        $this->assertSame(2, $row['units_lent']);
    }

    public function test_only_director_and_admin_can_see_kpis(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_WAREHOUSE, 'is_active' => true]));
        $this->getJson('/api/dashboard/kpis')->assertForbidden();
    }

    public function test_period_presets_resolve_to_expected_ranges(): void
    {
        $this->travelTo(now()->setDate(2026, 8, 15));

        $this->assertSame(['from' => '2026-07-01', 'to' => '2026-12-31'], DashboardPeriod::resolve('CURRENT_SEMESTER')->toArray());
        $this->assertSame(['from' => '2026-01-01', 'to' => '2026-12-31'], DashboardPeriod::resolve('YEAR')->toArray());
        $this->assertSame(['from' => '2026-08-01', 'to' => '2026-08-31'], DashboardPeriod::resolve('CURRENT_MONTH')->toArray());
        $this->assertSame(['from' => '2026-08-09', 'to' => '2026-08-15'], DashboardPeriod::resolve('LAST_7_DAYS')->toArray());
        $this->assertNull(DashboardPeriod::resolve(null));

        $this->expectException(ValidationException::class);
        DashboardPeriod::resolve('xyz');
    }
}
