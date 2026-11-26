<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * «Mis descargas» `GET /api/me/report-downloads` (docs/centro-reportes.md, decisión 13).
 */
class ReportDownloadsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_lists_only_my_downloads_with_the_route_and_parameters_to_repeat_them(): void
    {
        $other = User::factory()->create(['role' => User::ROLE_DIRECTOR, 'is_active' => true]);
        Sanctum::actingAs($other);
        $this->get('/api/products/export')->assertOk();

        $me = User::factory()->create(['role' => User::ROLE_WAREHOUSE, 'is_active' => true]);
        Sanctum::actingAs($me);
        // Exportación anterior al centro de reportes: sin `path`, no se puede repetir y no se lista.
        AuditLog::create(['user_id' => $me->id, 'action' => AuditLog::REPORT_EXPORT_ACTION, 'auditable_type' => AuditLog::REPORT_TYPE, 'auditable_id' => 0, 'new_values' => ['report' => 'estudiantes', 'filters' => [], 'rows' => 3]]);
        Product::factory()->create(['name' => 'Producto descargado']);
        $this->get('/api/products/export?search=descargado&type=insumo')->assertOk();
        $this->get('/api/products/export?search=descargado&preview=1')->assertOk();

        $data = $this->getJson('/api/me/report-downloads')->assertOk()->json('data');
        $this->assertCount(1, $data, 'solo mis descargas, sin vistas previas');
        $this->assertSame('inventario', $data[0]['report']);
        $this->assertSame('Inventario', $data[0]['title']);
        $this->assertSame('/products/export', $data[0]['path']);
        $this->assertEquals(['search' => 'descargado', 'type' => 'insumo'], $data[0]['query'], 'mismos parámetros (el orden lo fija jsonb)');
        $this->assertSame('descargado', $data[0]['filters']['Búsqueda']);
        $this->assertArrayHasKey('created_at', $data[0]);

        // «Repetir» vuelve a pedir la misma ruta con los mismos parámetros.
        $this->get('/api'.$data[0]['path'].'?'.http_build_query($data[0]['query']))->assertOk();
        $this->assertCount(2, $this->getJson('/api/me/report-downloads')->json('data'));
    }

    public function test_roles(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_TEACHER, 'is_active' => true]));
        $this->getJson('/api/me/report-downloads')->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]));
        $this->getJson('/api/me/report-downloads')->assertOk()->assertJsonPath('data', []);
    }
}
