<?php

namespace Tests\Feature;

use App\Models\AnnualBudget;
use App\Models\Cajon;
use App\Models\EquipmentUnit;
use App\Models\InvoiceIntake;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsProductPayload;
use Tests\TestCase;

class InvoiceIntakeTest extends TestCase
{
    use BuildsProductPayload;
    use DatabaseTransactions;

    private const INVOICE_TEXT = <<<'TXT'
Comercial ElectroChile S.A.            R.U.T.: 76.123.456-7
FACTURA ELECTRONICA N° 4521

Descripcion                     Cant      Precio        Total
Osciloscopio Digital 100MHz        5     250.000    1.250.000
TXT;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function draft(?AnnualBudget $budget = null): InvoiceIntake
    {
        return InvoiceIntake::factory()->create([
            'annual_budget_id' => ($budget ?? AnnualBudget::factory()->create())->id,
            'pdf_path' => UploadedFile::fake()->create('f.pdf', 10, 'application/pdf')->store('invoice-intakes', 'local'),
        ]);
    }

    public function test_upload_creates_draft_with_ocr_payload_and_private_file(): void
    {
        Process::fake(['*pdftotext*' => Process::result(self::INVOICE_TEXT)]);
        $budget = AnnualBudget::factory()->create();
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/invoice-intakes', [
            'file' => UploadedFile::fake()->create('factura.pdf', 100, 'application/pdf'),
            'annual_budget_id' => $budget->id,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'borrador')
            ->assertJsonPath('data.document_number', '4521')
            ->assertJsonPath('data.raw_ocr_payload.items.0.name', 'Osciloscopio Digital 100MHz')
            ->assertJsonPath('data.has_file', true);

        $intake = InvoiceIntake::findOrFail($response->json('data.id'));
        Storage::disk('local')->assertExists($intake->pdf_path);
        $this->assertSame($this->admin->id, $intake->user_id);
        $this->assertSame(0, $intake->items()->count());
    }

    public function test_upload_without_ocr_still_creates_empty_draft(): void
    {
        Process::fake(fn () => Process::result('', 'pdftotext: not found', 127));
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/invoice-intakes', [
            'file' => UploadedFile::fake()->create('factura.pdf', 100, 'application/pdf'),
            'annual_budget_id' => AnnualBudget::factory()->create()->id,
        ])->assertCreated()->assertJsonPath('data.status', 'borrador');
    }

    public function test_upload_validation(): void
    {
        Sanctum::actingAs($this->admin);
        $budget = AnnualBudget::factory()->create();

        $this->postJson('/api/invoice-intakes', ['annual_budget_id' => $budget->id])
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->postJson('/api/invoice-intakes', [
            'file' => UploadedFile::fake()->create('x.txt', 10, 'text/plain'),
            'annual_budget_id' => $budget->id,
        ])->assertStatus(422)->assertJsonValidationErrors('file');
        $this->postJson('/api/invoice-intakes', [
            'file' => UploadedFile::fake()->create('f.pdf', 10, 'application/pdf'),
            'annual_budget_id' => 999999,
        ])->assertStatus(422)->assertJsonValidationErrors('annual_budget_id');
    }

    public function test_confirm_deducts_budget_and_receives_stock(): void
    {
        $budget = AnnualBudget::factory()->create(['initial_amount' => 1000000, 'available_amount' => 1000000]);
        $intake = $this->draft($budget);
        $supply = Product::factory()->supply()->create(['quantity' => 3, 'stock_minimo' => 1]);
        $equipment = Product::factory()->create(array_merge($this->unitPayload(), ['quantity' => 0]));
        $cajon = Cajon::factory()->create();
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/invoice-intakes/{$intake->id}/confirm", [
            'document_number' => 'F-100',
            'items' => [
                ['product_id' => $supply->id, 'quantity' => 10, 'unit_price' => 1500, 'detected_name' => 'Cable'],
                ['product_id' => $equipment->id, 'quantity' => 2, 'unit_price' => 250000, 'cajon_id' => $cajon->id],
            ],
        ])->assertOk()
            ->assertJsonPath('data.status', 'confirmado')
            ->assertJsonPath('data.total_deducted', '515000.00')
            ->assertJsonCount(2, 'data.items');

        $this->assertSame('485000.00', $budget->fresh()->available_amount);
        $this->assertSame(13, $supply->fresh()->quantity);
        $this->assertSame(2, EquipmentUnit::where('product_id', $equipment->id)->count());
        $this->assertSame(2, $equipment->fresh()->quantity);
        $this->assertDatabaseHas('invoice_intake_items', ['invoice_intake_id' => $intake->id, 'product_id' => $supply->id, 'subtotal' => '15000.00']);
    }

    public function test_confirm_fails_when_budget_is_insufficient_and_changes_nothing(): void
    {
        $budget = AnnualBudget::factory()->create(['initial_amount' => 1000, 'available_amount' => 1000]);
        $intake = $this->draft($budget);
        $supply = Product::factory()->supply()->create(['quantity' => 3]);
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/invoice-intakes/{$intake->id}/confirm", [
            'document_number' => 'F-1',
            'items' => [['product_id' => $supply->id, 'quantity' => 10, 'unit_price' => 500]],
        ])->assertStatus(422)->assertJsonValidationErrors('items');

        $this->assertSame('1000.00', $budget->fresh()->available_amount);
        $this->assertSame(3, $supply->fresh()->quantity);
        $this->assertSame('borrador', $intake->fresh()->status);
    }

    public function test_confirm_twice_is_rejected(): void
    {
        $intake = $this->draft();
        $supply = Product::factory()->supply()->create(['quantity' => 3]);
        $payload = ['document_number' => 'F-1', 'items' => [['product_id' => $supply->id, 'quantity' => 1, 'unit_price' => 10]]];
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/invoice-intakes/{$intake->id}/confirm", $payload)->assertOk();
        $this->postJson("/api/invoice-intakes/{$intake->id}/confirm", $payload)->assertStatus(422)->assertJsonValidationErrors('status');
        $this->assertSame(4, $supply->fresh()->quantity);
    }

    public function test_confirm_validation_unknown_product_and_unit_without_cajon(): void
    {
        $intake = $this->draft();
        $equipment = Product::factory()->create($this->unitPayload());
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/invoice-intakes/{$intake->id}/confirm", [
            'document_number' => 'F-1',
            'items' => [['product_id' => 999999, 'quantity' => 1, 'unit_price' => 10]],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.product_id');

        $this->postJson("/api/invoice-intakes/{$intake->id}/confirm", [
            'document_number' => 'F-1',
            'items' => [['product_id' => $equipment->id, 'quantity' => 1, 'unit_price' => 10]],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.cajon_id');

        $this->postJson("/api/invoice-intakes/{$intake->id}/confirm", ['document_number' => 'F-1', 'items' => []])
            ->assertStatus(422)->assertJsonValidationErrors('items');

        $this->assertSame('borrador', $intake->fresh()->status);
    }

    public function test_cancel_draft_and_second_cancel_is_rejected(): void
    {
        $intake = $this->draft();
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/invoice-intakes/{$intake->id}/cancel")->assertOk()->assertJsonPath('data.status', 'anulado');
        $this->postJson("/api/invoice-intakes/{$intake->id}/cancel")->assertStatus(422);

    }

    public function test_list_filters_show_and_file_download(): void
    {
        $intake = $this->draft();
        $other = InvoiceIntake::factory()->create(['status' => InvoiceIntake::STATUS_CONFIRMED]);
        Sanctum::actingAs($this->admin);

        $ids = collect($this->getJson('/api/invoice-intakes?status=borrador')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($intake->id));
        $this->assertFalse($ids->contains($other->id));

        $this->getJson("/api/invoice-intakes?annual_budget_id={$other->annual_budget_id}")->assertJsonCount(1, 'data');
        $this->getJson("/api/invoice-intakes/{$intake->id}")->assertOk()->assertJsonPath('data.id', $intake->id);
        $this->get("/api/invoice-intakes/{$intake->id}/file", ['Accept' => 'application/json'])->assertOk();
        $this->getJson("/api/invoice-intakes/{$other->id}/file")->assertNotFound();
    }

    public function test_roles_and_foreign_career_are_forbidden(): void
    {
        $intake = $this->draft();
        $file = UploadedFile::fake()->create('f.pdf', 10, 'application/pdf');

        foreach ([User::ROLE_WAREHOUSE, User::ROLE_TEACHER] as $role) {
            Sanctum::actingAs($this->user($role));
            $this->postJson('/api/invoice-intakes', ['file' => $file, 'annual_budget_id' => $intake->annual_budget_id])->assertForbidden();
            $this->getJson('/api/invoice-intakes')->assertForbidden();
            $this->getJson("/api/invoice-intakes/{$intake->id}")->assertForbidden();
            $this->postJson("/api/invoice-intakes/{$intake->id}/cancel")->assertForbidden();
        }

        $director = $this->user(User::ROLE_DIRECTOR);
        Sanctum::actingAs($director);
        $this->postJson('/api/invoice-intakes', ['file' => $file, 'annual_budget_id' => $intake->annual_budget_id])->assertForbidden();
        $this->getJson("/api/invoice-intakes/{$intake->id}")->assertForbidden();
        $this->get("/api/invoice-intakes/{$intake->id}/file", ['Accept' => 'application/json'])->assertForbidden();
        $this->postJson("/api/invoice-intakes/{$intake->id}/confirm", ['document_number' => 'x', 'items' => [['product_id' => 1, 'quantity' => 1, 'unit_price' => 1]]])->assertForbidden();
        $this->postJson("/api/invoice-intakes/{$intake->id}/cancel")->assertForbidden();
        $this->assertCount(0, $this->getJson('/api/invoice-intakes')->assertOk()->json('data'));

        $director->careers()->attach($intake->annualBudget->career_id);
        $this->getJson("/api/invoice-intakes/{$intake->id}")->assertOk();
        $this->postJson("/api/invoice-intakes/{$intake->id}/cancel")->assertOk();
    }
}
