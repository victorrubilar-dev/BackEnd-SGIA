<?php

namespace Tests\Feature;

use App\Models\EquipmentUnit;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ReadsXlsx;
use Tests\TestCase;

/**
 * Reporte Excel de unidades `GET /api/equipment-units/export` (docs/reportes-excel.md).
 */
class EquipmentUnitExportTest extends TestCase
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

    public function test_exports_units_with_the_list_filters(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $product = Product::factory()->create(['name' => 'Osciloscopio de prueba', 'type' => Product::TYPE_EQUIPMENT]);
        $available = EquipmentUnit::factory()->create(['product_id' => $product->id, 'serial_number' => 'OSC-1']);
        $repair = EquipmentUnit::factory()->create(['product_id' => $product->id, 'status' => EquipmentUnit::STATUS_IN_REPAIR]);

        $response = $this->get("/api/equipment-units/export?product_id={$product->id}&status=disponible")->assertOk();
        $this->assertMatchesRegularExpression('/filename="equipos-\d{8}-\d{6}\.xlsx"/', (string) $response->headers->get('Content-Disposition'));

        $rows = $this->xlsxRecords($response, 'Unidades');
        $this->assertSame([$available->code], array_column($rows, 'Código'));
        $this->assertSame('Osciloscopio de prueba', $rows[0]['Producto']);
        $this->assertSame('Disponible', $rows[0]['Estado']);
        $this->assertSame('OSC-1', $rows[0]['N° de serie']);
        $this->assertSame(0, $rows[0]['Préstamos']);
        $this->assertNotContains($repair->code, array_column($rows, 'Código'));
        $this->assertSame('Producto: Osciloscopio de prueba; Estado: Disponible', $this->xlsxInfo($response)['Filtros']);
    }

    public function test_roles_and_validation(): void
    {
        foreach ([User::ROLE_ADMIN, User::ROLE_TEACHER] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/equipment-units/export')->assertForbidden();
        }

        $this->actingAsRole(User::ROLE_DIRECTOR);
        $this->getJson('/api/equipment-units/export?status=perdido')->assertUnprocessable()->assertJsonValidationErrors('status');
    }
}
