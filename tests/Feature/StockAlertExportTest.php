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

    public function test_roles_and_validation(): void
    {
        foreach ([User::ROLE_ADMIN, User::ROLE_TEACHER] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/alerts/export')->assertForbidden();
        }

        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $this->getJson('/api/alerts/export?alert_type=roja')->assertUnprocessable()->assertJsonValidationErrors('alert_type');
    }
}
