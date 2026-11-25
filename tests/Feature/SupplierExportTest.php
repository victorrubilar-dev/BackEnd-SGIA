<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ReadsXlsx;
use Tests\TestCase;

/**
 * Reporte Excel de proveedores `GET /api/suppliers/export` (docs/reportes-excel.md).
 */
class SupplierExportTest extends TestCase
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

    public function test_exports_suppliers_with_the_list_filters(): void
    {
        $this->actingAsRole(User::ROLE_DIRECTOR);
        $tag = 'PRV'.fake()->unique()->numerify('#####');
        Supplier::factory()->withAreas(['informatica', 'ciberseguridad'])->create([
            'name' => "{$tag} Redes SpA", 'rut' => '76.086.428-5', 'category' => 'redes', 'contact_name' => 'Ana Soto', 'email' => "ventas@{$tag}.cl", 'phone' => '+56 9 1111 2222',
        ]);
        Supplier::factory()->withAreas(['diseno'])->create(['name' => "{$tag} Gráfica", 'is_active' => false]);
        Supplier::factory()->create(['name' => 'Proveedor sin etiqueta']);

        $response = $this->get("/api/suppliers/export?search={$tag}")->assertOk();
        $this->assertMatchesRegularExpression('/filename="proveedores-\d{8}-\d{6}\.xlsx"/', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame(['Proveedores', 'Info'], array_keys($this->readXlsx($response)));

        $suppliers = collect($this->xlsxRecords($response, 'Proveedores'))->keyBy('Razón social');
        $this->assertSame(["{$tag} Gráfica", "{$tag} Redes SpA"], $suppliers->keys()->all());
        $row = $suppliers["{$tag} Redes SpA"];
        $this->assertSame('76086428-5', $row['RUT']);
        $this->assertSame('Ciberseguridad, Informática', $row['Áreas']);
        $this->assertSame('Ana Soto', $row['Contacto']);
        $this->assertSame("ventas@{$tag}.cl", $row['Correo']);
        $this->assertSame('sí', $row['Activo']);
        $this->assertSame('no', $suppliers["{$tag} Gráfica"]['Activo']);
        $this->assertSame("Búsqueda: {$tag}", $this->xlsxInfo($response)['Filtros']);
    }

    public function test_area_and_active_filters_exclude_suppliers(): void
    {
        $this->actingAsRole(User::ROLE_DIRECTOR);
        $tag = 'PRA'.fake()->unique()->numerify('#####');
        Supplier::factory()->withAreas(['informatica'])->create(['name' => "{$tag} Uno"]);
        Supplier::factory()->withAreas(['diseno'])->create(['name' => "{$tag} Dos"]);
        Supplier::factory()->withAreas(['diseno'])->create(['name' => "{$tag} Tres", 'is_active' => false]);

        $response = $this->get("/api/suppliers/export?search={$tag}&area=diseno&is_active=1")->assertOk();
        $this->assertSame(["{$tag} Dos"], array_column($this->xlsxRecords($response, 'Proveedores'), 'Razón social'));
        $filters = $this->xlsxInfo($response)['Filtros'];
        $this->assertStringContainsString('Área: Diseño', $filters);
        $this->assertStringContainsString('Activo: sí', $filters);
    }

    public function test_roles_and_validation(): void
    {
        foreach ([User::ROLE_ADMIN, User::ROLE_WAREHOUSE, User::ROLE_TEACHER] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/suppliers/export')->assertForbidden();
        }

        $this->actingAsRole(User::ROLE_DIRECTOR);
        $this->getJson('/api/suppliers/export?area=cocina')->assertUnprocessable()->assertJsonValidationErrors('area');
        $this->getJson('/api/suppliers/export?is_active=tal-vez')->assertUnprocessable()->assertJsonValidationErrors('is_active');
    }
}
