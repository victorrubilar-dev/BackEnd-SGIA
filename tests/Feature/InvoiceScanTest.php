<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
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
            'email' => 'admin_inv_'.uniqid().'@inacap.cl',
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $this->director = User::factory()->create([
            'email' => 'dir_inv_'.uniqid().'@inacap.cl',
            'role' => User::ROLE_DIRECTOR,
            'is_active' => true,
        ]);

        $this->warehouse = User::factory()->create([
            'email' => 'wh_inv_'.uniqid().'@inacap.cl',
            'role' => User::ROLE_WAREHOUSE,
            'is_active' => true,
        ]);

        $this->teacher = User::factory()->create([
            'email' => 'teach_inv_'.uniqid().'@inacap.cl',
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

    private const INVOICE_TEXT = <<<'TXT'
Comercial ElectroChile S.A.            R.U.T.: 76.123.456-7
Av. Alemania 123, Temuco               FACTURA ELECTRONICA N° 4521
S.I.I. - TEMUCO

Descripcion                     Cant      Precio        Total
Osciloscopio Digital 100MHz        5     250.000    1.250.000
Generador de Funciones 25MHz       4     $180.000     $720.000
Neto                                              1.970.000
TXT;

    public function test_director_and_admin_can_scan_invoice_and_receive_draft_products(): void
    {
        Supplier::factory()->create(['name' => 'Comercial ElectroChile S.A.']);
        Process::fake(['*pdftotext*' => Process::result(self::INVOICE_TEXT)]);

        foreach ([$this->director, $this->admin] as $user) {
            Sanctum::actingAs($user);

            $this->postJson('/api/invoices/scan', [
                'invoice_file' => UploadedFile::fake()->create('factura.pdf', 100, 'application/pdf'),
            ])->assertOk()
                ->assertJsonPath('invoice_number', '4521')
                ->assertJsonPath('supplier_name', 'Comercial ElectroChile S.A.')
                ->assertJsonPath('draft_products', [
                    ['name' => 'Osciloscopio Digital 100MHz', 'quantity' => 5, 'unit_price' => 250000, 'area' => null],
                    ['name' => 'Generador de Funciones 25MHz', 'quantity' => 4, 'unit_price' => 180000, 'area' => null],
                ]);
        }
    }

    public function test_image_invoice_goes_through_tesseract(): void
    {
        Process::fake(['*tesseract*' => Process::result(self::INVOICE_TEXT)]);
        Sanctum::actingAs($this->director);

        $this->postJson('/api/invoices/scan', [
            'invoice_file' => UploadedFile::fake()->create('factura.jpg', 50, 'image/jpeg'),
        ])->assertOk()->assertJsonPath('invoice_number', '4521');

        Process::assertRan(fn ($process) => $process->command[0] === 'tesseract');
    }

    public function test_scanned_pdf_falls_back_to_tesseract(): void
    {
        Process::fake([
            '*pdftotext*' => Process::result(''),
            '*pdftoppm*' => Process::result(''),
        ]);
        Sanctum::actingAs($this->director);

        $this->postJson('/api/invoices/scan', [
            'invoice_file' => UploadedFile::fake()->create('escaneo.pdf', 100, 'application/pdf'),
        ])->assertOk()->assertJsonPath('draft_products', []);

        Process::assertRan(fn ($process) => $process->command[0] === 'pdftoppm');
    }

    public function test_scan_returns_503_when_ocr_binary_fails(): void
    {
        Process::fake(['*' => Process::result('', 'command not found', 127)]);
        Sanctum::actingAs($this->director);

        $this->postJson('/api/invoices/scan', [
            'invoice_file' => UploadedFile::fake()->create('factura.pdf', 100, 'application/pdf'),
        ])->assertStatus(503);
    }

    public function test_json_files_are_no_longer_accepted(): void
    {
        Sanctum::actingAs($this->director);

        $this->postJson('/api/invoices/scan', [
            'invoice_file' => UploadedFile::fake()->createWithContent('factura.json', '{"items":[]}'),
        ])->assertStatus(422)->assertJsonValidationErrors('invoice_file');
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
        $updateResponse = $this->patchJson('/api/products/'.$productId, [
            'name' => 'Generador de Señales Digital Modificado',
        ]);
        $updateResponse->assertStatus(200);
        $updateResponse->assertJsonFragment(['name' => 'Generador de Señales Digital Modificado']);

        // Test PATCH /api/products/{id}/status
        $statusResponse = $this->patchJson('/api/products/'.$productId.'/status', [
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
