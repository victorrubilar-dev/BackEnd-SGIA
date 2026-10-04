<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceScanTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;
    protected User $director;
    protected User $warehouse;
    protected User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'email' => 'admin_inv_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $this->director = User::factory()->create([
            'email' => 'dir_inv_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_DIRECTOR,
            'is_active' => true,
        ]);

        $this->warehouse = User::factory()->create([
            'email' => 'wh_inv_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_WAREHOUSE,
            'is_active' => true,
        ]);

        $this->teacher = User::factory()->create([
            'email' => 'teach_inv_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_TEACHER,
            'is_active' => true,
        ]);
    }

    public function test_guest_cannot_scan_invoice(): void
    {
        $file = UploadedFile::fake()->create('factura.pdf', 100, 'application/pdf');

        $this->postJson('/api/invoices/scan', [
            'invoice_file' => $file,
        ])->assertStatus(401);
    }

    public function test_warehouse_and_teacher_cannot_scan_invoice(): void
    {
        $file = UploadedFile::fake()->create('factura.pdf', 100, 'application/pdf');

        Sanctum::actingAs($this->warehouse);
        $this->postJson('/api/invoices/scan', ['invoice_file' => $file])->assertStatus(403);

        Sanctum::actingAs($this->teacher);
        $this->postJson('/api/invoices/scan', ['invoice_file' => $file])->assertStatus(403);
    }

    public function test_director_and_admin_can_scan_invoice_and_receive_draft_products(): void
    {
        Sanctum::actingAs($this->director);

        $jsonPayload = json_encode([
            'invoice_number' => 'FAC-998877',
            'supplier_name' => 'Proveedor Factura Electrónica S.A.',
            'items' => [
                [
                    'name' => 'Generador de Señales Digital',
                    'quantity' => 8,
                    'unit_price' => 120000,
                    'area' => 'Electrónica',
                ],
            ],
        ]);

        $file = UploadedFile::fake()->createWithContent('factura.json', $jsonPayload);

        $response = $this->postJson('/api/invoices/scan', [
            'invoice_file' => $file,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Factura escaneada y procesada exitosamente.',
            'invoice_number' => 'FAC-998877',
            'supplier_name' => 'Proveedor Factura Electrónica S.A.',
            'draft_products' => [
                [
                    'name' => 'Generador de Señales Digital',
                    'quantity' => 8,
                    'unit_price' => 120000,
                    'area' => 'Electrónica',
                ],
            ],
        ]);
    }

    public function test_director_can_confirm_draft_and_create_product(): void
    {
        Sanctum::actingAs($this->director);

        $supplier = Supplier::factory()->create(['name' => 'Proveedor Confirmado S.A.']);

        $response = $this->postJson('/api/products', [
            'name' => 'Generador de Señales Digital Confirmado',
            'quantity' => 8,
            'supplier_id' => $supplier->id,
            'area' => 'Electrónica',
            'stock_minimo' => 4,
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'data' => [
                'name' => 'Generador de Señales Digital Confirmado',
                'quantity' => 8,
                'supplier_id' => $supplier->id,
                'area' => 'Electrónica',
            ],
        ]);

        $productId = $response->json('data.id');

        // Test PATCH /api/products/{id}
        $updateResponse = $this->patchJson('/api/products/' . $productId, [
            'name' => 'Generador de Señales Digital Modificado',
        ]);
        $updateResponse->assertStatus(200);
        $updateResponse->assertJsonFragment(['name' => 'Generador de Señales Digital Modificado']);

        // Test PATCH /api/products/{id}/status
        $statusResponse = $this->patchJson('/api/products/' . $productId . '/status', [
            'is_active' => false,
        ]);
        $statusResponse->assertStatus(200);
        $statusResponse->assertJson([
            'message' => 'Estado de producto actualizado exitosamente.',
            'product' => [
                'id' => $productId,
                'is_active' => false,
            ],
        ]);
    }
}
