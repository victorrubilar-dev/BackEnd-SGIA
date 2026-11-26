<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockAlert;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ReadsXlsx;
use Tests\TestCase;

/**
 * Reporte Excel de alertas de stock `GET /api/alerts/export` (docs/reportes-excel.md).
 */
class StockAlertExportTest extends TestCase
{
    use DatabaseTransactions;
    use ReadsXlsx;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_exports_open_alerts_by_default_and_filters_by_type(): void
    {
        $this->actingAsRole(User::ROLE_DIRECTOR);
        $tag = 'ALR'.fake()->unique()->numerify('####');
        $low = Product::factory()->create(['name' => "{$tag} Cable", 'type' => Product::TYPE_SUPPLY, 'quantity' => 2, 'stock_minimo' => 5]);
        $ok = Product::factory()->create(['name' => "{$tag} Conector", 'type' => Product::TYPE_SUPPLY, 'quantity' => 80, 'stock_minimo' => 5]);
        StockAlert::create(['product_id' => $low->id, 'alert_type' => StockAlert::TYPE_CRITICAL, 'current_stock' => 2, 'stock_minimo' => 5, 'message' => 'Stock crítico', 'is_resolved' => false]);
        StockAlert::create(['product_id' => $ok->id, 'alert_type' => StockAlert::TYPE_WARNING, 'current_stock' => 8, 'stock_minimo' => 5, 'message' => 'Advertencia', 'is_resolved' => true]);

        $response = $this->get('/api/alerts/export?alert_type=critical')->assertOk();
        $rows = collect($this->xlsxRecords($response, 'Alertas'))->where('Producto', "{$tag} Cable");
        $this->assertCount(1, $rows);
        $this->assertSame(['Crítica', 'Abierta', 2, 5], [$rows->first()['Tipo'], $rows->first()['Estado'], $rows->first()['Stock al alertar'], $rows->first()['Stock mínimo']]);
        $this->assertSame('Estado: abiertas; Tipo: Crítica', $this->xlsxInfo($response)['Filtros']);
        $this->assertFalse(collect($this->xlsxRecords($response, 'Alertas'))->contains('Producto', "{$tag} Conector"), 'sin is_resolved: solo abiertas');

        $resolved = collect($this->xlsxRecords($this->get('/api/alerts/export?is_resolved=1')->assertOk(), 'Alertas'));
        $this->assertTrue($resolved->contains(fn ($r) => ($r['Producto'] ?? null) === "{$tag} Conector" && $r['Estado'] === 'Resuelta'));
        $this->assertFalse($resolved->contains('Producto', "{$tag} Cable"));
    }

    /**
     * Alerta resuelta de un producto con stock holgado (`reconcile` no la toca) creada en `$createdAt`.
     */
    private function resolvedAlert(Product $product, string $type, string $createdAt): StockAlert
    {
        $alert = StockAlert::create(['product_id' => $product->id, 'alert_type' => $type, 'current_stock' => 3, 'stock_minimo' => 5, 'message' => 'Histórica', 'is_resolved' => true]);
        $alert->forceFill(['created_at' => $createdAt])->save();

        return $alert;
    }

    public function test_period_search_and_summary_by_type_and_status(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $tag = 'ALP'.fake()->unique()->numerify('#####');
        $cable = Product::factory()->create(['name' => "{$tag} Cable", 'barcode' => "{$tag}-CB", 'type' => Product::TYPE_SUPPLY, 'quantity' => 80, 'stock_minimo' => 5]);
        $mouse = Product::factory()->create(['name' => 'Mouse óptico', 'barcode' => "{$tag}-MS", 'type' => Product::TYPE_SUPPLY, 'quantity' => 80, 'stock_minimo' => 5]);
        $other = Product::factory()->create(['name' => 'Otro producto', 'type' => Product::TYPE_SUPPLY, 'quantity' => 80, 'stock_minimo' => 5]);
        $this->resolvedAlert($cable, StockAlert::TYPE_CRITICAL, '2026-03-10 10:00:00');
        $this->resolvedAlert($mouse, StockAlert::TYPE_WARNING, '2026-03-31 23:30:00');
        $this->resolvedAlert($cable, StockAlert::TYPE_WARNING, '2026-04-01 00:10:00');
        $this->resolvedAlert($other, StockAlert::TYPE_CRITICAL, '2026-03-15 10:00:00');

        // `search` por nombre o por código de barras; `from`/`to` por la fecha de la alerta (días completos).
        $response = $this->get("/api/alerts/export?is_resolved=1&search={$tag}&from=2026-03-01&to=2026-03-31")->assertOk();
        $rows = $this->xlsxRecords($response, 'Alertas');
        $this->assertEqualsCanonicalizing(["{$tag} Cable", 'Mouse óptico'], array_column($rows, 'Producto'));
        $this->assertStringContainsString("Búsqueda: {$tag}; Desde: 2026-03-01; Hasta: 2026-03-31", $this->xlsxInfo($response)['Filtros']);

        $summary = $this->xlsxSummary($response);
        $this->assertContains('Tipo|Crítica|1', $summary);
        $this->assertContains('Tipo|Advertencia|1', $summary);
        $this->assertContains('Estado|Resuelta|2', $summary);

        // El listado de la bandeja acepta los mismos filtros.
        $listed = $this->getJson("/api/alerts/critical-stock?is_resolved=1&search={$tag}-MS&from=2026-03-01&to=2026-03-31")->assertOk()->json('data');
        $this->assertSame([$mouse->id], array_column($listed, 'product_id'));
        $this->assertCount(1, $this->getJson("/api/alerts/critical-stock?is_resolved=1&search={$tag}&from=2026-04-01")->assertOk()->json('data'));
    }

    public function test_roles_and_validation(): void
    {
        foreach ([User::ROLE_ADMIN, User::ROLE_TEACHER] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/alerts/export')->assertForbidden();
        }

        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $this->getJson('/api/alerts/export?alert_type=roja')->assertUnprocessable()->assertJsonValidationErrors('alert_type');
        $this->getJson('/api/alerts/export?from=2026-03-31&to=2026-03-01')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson('/api/alerts/critical-stock?from=2026-03-31&to=2026-03-01')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson('/api/alerts/export?from=ayer')->assertUnprocessable()->assertJsonValidationErrors('from');
    }
}
