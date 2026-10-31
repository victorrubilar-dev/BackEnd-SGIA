<?php

namespace Tests\Feature;

use App\Models\EquipmentUnit;
use App\Models\IncidentReport;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\MaintenanceRecord;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Hoja de vida por unidad: informes con unidad y foto, PDFs y filtros (plan Equipos, fase 8).
 */
class UnitLifeSheetTest extends TestCase
{
    use DatabaseTransactions;

    private User $teacher;

    private User $warehouse;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->teacher = User::factory()->create(['role' => User::ROLE_TEACHER, 'is_active' => true]);
        $this->warehouse = User::factory()->create(['role' => User::ROLE_WAREHOUSE, 'is_active' => true]);
        $this->director = User::factory()->create(['role' => User::ROLE_DIRECTOR, 'is_active' => true]);
    }

    private function pdfText(string $pdf): string
    {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $matches);
        $text = '';
        foreach ($matches[1] ?? [] as $raw) {
            $decoded = @gzuncompress($raw) ?: @gzuncompress($raw."\r");
            $text .= $decoded === false ? $raw : $decoded;
        }

        return $text;
    }

    public function test_report_on_equipment_requires_a_unit_of_that_product(): void
    {
        $unit = EquipmentUnit::factory()->create();
        $other = EquipmentUnit::factory()->create();
        Sanctum::actingAs($this->teacher);

        $this->postJson("/api/equipment/{$unit->product_id}/reports", ['description' => 'No enciende el equipo.'])
            ->assertStatus(422)->assertJsonValidationErrors('equipment_unit_id');
        $this->postJson("/api/equipment/{$unit->product_id}/reports", ['description' => 'No enciende.', 'equipment_unit_id' => $other->id])
            ->assertStatus(422)->assertJsonValidationErrors('equipment_unit_id');

        $this->postJson("/api/equipment/{$unit->product_id}/reports", ['description' => 'No enciende el equipo.', 'equipment_unit_id' => $unit->id])
            ->assertCreated()
            ->assertJsonPath('data.equipment_unit_id', $unit->id)
            ->assertJsonPath('data.equipment_unit.code', $unit->code)
            ->assertJsonPath('data.request_type', 'falla')
            ->assertJsonPath('data.has_evidence_photo', false);
    }

    public function test_supply_report_does_not_need_a_unit(): void
    {
        $supply = Product::factory()->supply()->create();
        Sanctum::actingAs($this->warehouse);

        $this->postJson("/api/equipment/{$supply->id}/reports", ['description' => 'Se acabó el insumo.', 'request_type' => 'reposicion'])
            ->assertCreated()->assertJsonPath('data.request_type', 'reposicion')->assertJsonPath('data.equipment_unit_id', null);
        $this->postJson("/api/equipment/{$supply->id}/reports", ['description' => 'x y z', 'request_type' => 'otro'])
            ->assertStatus(422)->assertJsonValidationErrors('request_type');
    }

    public function test_evidence_photo_is_private_and_downloadable_by_staff(): void
    {
        $unit = EquipmentUnit::factory()->create();
        Sanctum::actingAs($this->teacher);

        $id = $this->postJson("/api/equipment/{$unit->product_id}/reports", [
            'description' => 'Pantalla rota.', 'equipment_unit_id' => $unit->id, 'request_type' => 'mantencion',
            'evidence_photo' => UploadedFile::fake()->create('foto.jpg', 100, 'image/jpeg'),
        ])->assertCreated()->assertJsonPath('data.has_evidence_photo', true)->assertJsonPath('data.request_type', 'mantencion')->json('data.id');

        $report = IncidentReport::findOrFail($id);
        Storage::disk('local')->assertExists($report->evidence_photo_path);
        Storage::disk('public')->assertMissing($report->evidence_photo_path);

        $this->get("/api/incident-reports/{$id}/evidence-photo")->assertForbidden();
        Sanctum::actingAs($this->director);
        $this->get("/api/incident-reports/{$id}/evidence-photo")->assertOk();

        $noPhoto = IncidentReport::factory()->create(['product_id' => $unit->product_id, 'equipment_unit_id' => $unit->id]);
        $this->getJson("/api/incident-reports/{$noPhoto->id}/evidence-photo")->assertNotFound();
    }

    public function test_evidence_photo_rejects_non_images_and_oversized_files(): void
    {
        $unit = EquipmentUnit::factory()->create();
        Sanctum::actingAs($this->teacher);
        $url = "/api/equipment/{$unit->product_id}/reports";

        $this->postJson($url, ['description' => 'abc def', 'equipment_unit_id' => $unit->id, 'evidence_photo' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])
            ->assertStatus(422)->assertJsonValidationErrors('evidence_photo');
        $this->postJson($url, ['description' => 'abc def', 'equipment_unit_id' => $unit->id, 'evidence_photo' => UploadedFile::fake()->create('x.jpg', 11000, 'image/jpeg')])
            ->assertStatus(422)->assertJsonValidationErrors('evidence_photo');
    }

    public function test_global_list_filters_by_unit_and_request_type(): void
    {
        $unit = EquipmentUnit::factory()->create();
        $other = EquipmentUnit::factory()->create();
        $a = IncidentReport::factory()->create(['product_id' => $unit->product_id, 'equipment_unit_id' => $unit->id, 'request_type' => 'mantencion']);
        IncidentReport::factory()->create(['product_id' => $other->product_id, 'equipment_unit_id' => $other->id]);
        Sanctum::actingAs($this->director);

        $this->getJson("/api/incident-reports?equipment_unit_id={$unit->id}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a->id);
        $this->getJson("/api/incident-reports?request_type=mantencion&equipment_unit_id={$unit->id}")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/incident-reports?request_type=reposicion&equipment_unit_id={$unit->id}")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_unit_reports_endpoint_returns_json_and_pdf(): void
    {
        $unit = EquipmentUnit::factory()->create(['code' => 'PRO-IC-000321']);
        $other = EquipmentUnit::factory()->create(['product_id' => $unit->product_id]);
        IncidentReport::factory()->count(2)->create(['product_id' => $unit->product_id, 'equipment_unit_id' => $unit->id]);
        IncidentReport::factory()->create(['product_id' => $unit->product_id, 'equipment_unit_id' => $other->id]);

        Sanctum::actingAs($this->teacher);
        $this->getJson("/api/equipment-units/{$unit->id}/reports")->assertForbidden();

        Sanctum::actingAs($this->director);
        $this->getJson("/api/equipment-units/{$unit->id}/reports")->assertOk()->assertJsonCount(2, 'data');

        $response = $this->get("/api/equipment-units/{$unit->id}/reports?format=pdf")->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('informes-PRO-IC-000321.pdf', $response->headers->get('Content-Disposition'));
    }

    public function test_unit_technical_sheet_pdf_includes_history(): void
    {
        $product = Product::factory()->create(['name' => 'Osciloscopio Rigol', 'maintenance_priority' => 'A', 'maintenance_frequency_months' => 12]);
        $unit = EquipmentUnit::factory()->create([
            'product_id' => $product->id, 'code' => 'PRO-IC-000654', 'serial_number' => 'SN-OSC-9',
            'next_maintenance_at' => '2027-03-15',
        ]);
        $loan = Loan::factory()->processed()->create(['borrower_name' => 'Docente Prestatario']);
        LoanItem::create(['loan_id' => $loan->id, 'product_id' => $product->id, 'name' => 'x', 'quantity' => 1])->units()->attach($unit->id);
        MaintenanceRecord::factory()->create(['equipment_unit_id' => $unit->id, 'maintenance_type' => 'calibracion']);
        IncidentReport::factory()->create(['product_id' => $product->id, 'equipment_unit_id' => $unit->id, 'title' => 'Falla en canal 2']);

        Sanctum::actingAs($this->teacher);
        $response = $this->get("/api/equipment-units/{$unit->id}/technical-sheet")->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString('hoja-de-vida-PRO-IC-000654.pdf', $response->headers->get('Content-Disposition'));

        $this->getJson('/api/equipment-units/999999/technical-sheet')->assertNotFound();
    }

    public function test_product_technical_sheet_lists_units(): void
    {
        $unit = EquipmentUnit::factory()->create(['code' => 'PRO-IC-000987']);
        Sanctum::actingAs($this->teacher);

        $response = $this->get("/api/equipment/{$unit->product_id}/technical-sheet")->assertOk();

        $this->assertStringStartsWith('%PDF', $response->getContent());
    }
}
