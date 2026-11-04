<?php

namespace Tests\Feature;

use App\Models\EquipmentUnit;
use App\Models\MaintenanceDocument;
use App\Models\MaintenanceRecord;
use App\Models\User;
use App\Services\EquipmentPdfService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * REQ-19: documentos de mantenimiento por unidad, en disco privado.
 */
class MaintenanceDocumentTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function pdf(string $name = 'certificado.pdf', int $kb = 100): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kb, 'application/pdf');
    }

    private function document(EquipmentUnit $unit, array $attributes = []): MaintenanceDocument
    {
        return MaintenanceDocument::create([
            'equipment_unit_id' => $unit->id,
            'uploaded_by' => User::factory()->create()->id,
            'document_type' => MaintenanceDocument::TYPE_MANUAL,
            'title' => 'Manual',
            'file_path' => 'maintenance-documents/x.pdf',
            ...$attributes,
        ]);
    }

    public function test_upload_list_and_download_from_private_disk(): void
    {
        $user = $this->actingAsRole(User::ROLE_WAREHOUSE);
        $unit = EquipmentUnit::factory()->create();
        $record = MaintenanceRecord::factory()->create(['equipment_unit_id' => $unit->id]);

        $response = $this->post("/api/equipment-units/{$unit->id}/maintenance-documents", [
            'document_type' => 'certificado_calibracion',
            'title' => 'Certificado 2026',
            'file' => $this->pdf(),
            'valid_until' => now()->addMonths(6)->toDateString(),
            'maintenance_record_id' => $record->id,
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.uploaded_by', $user->id)
            ->assertJsonPath('data.maintenance_record_id', $record->id)
            ->assertJsonPath('data.original_name', 'certificado.pdf')
            ->assertJsonPath('data.is_expired', false);

        $document = MaintenanceDocument::findOrFail($response->json('data.id'));
        Storage::disk('local')->assertExists($document->file_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertStringEndsWith("/api/maintenance-documents/{$document->id}/file", $response->json('data.file_url'));

        $this->actingAsRole(User::ROLE_DIRECTOR);
        $this->getJson("/api/equipment-units/{$unit->id}/maintenance-documents")->assertOk()->assertJsonPath('data.0.id', $document->id);
        $this->get("/api/maintenance-documents/{$document->id}/file")->assertOk()->assertDownload('certificado.pdf');
        $this->getJson("/api/maintenance-records/{$record->id}")->assertOk()->assertJsonPath('data.documents.0.id', $document->id);
    }

    public function test_upload_validation(): void
    {
        $this->actingAsRole(User::ROLE_ADMIN);
        $unit = EquipmentUnit::factory()->create();
        $foreignRecord = MaintenanceRecord::factory()->create();
        $url = "/api/equipment-units/{$unit->id}/maintenance-documents";
        $base = ['document_type' => 'manual', 'title' => 'Manual'];

        $this->postJson($url, $base + ['file' => UploadedFile::fake()->create('foto.png', 10, 'image/png')])
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->postJson($url, $base + ['file' => $this->pdf(kb: 10241)])
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->postJson($url, $base + ['file' => $this->pdf(), 'maintenance_record_id' => $foreignRecord->id])
            ->assertStatus(422)->assertJsonValidationErrors('maintenance_record_id');
        $this->postJson($url, ['file' => $this->pdf(), 'document_type' => 'factura'])
            ->assertStatus(422)->assertJsonValidationErrors(['document_type', 'title']);
    }

    public function test_filters_and_expiration_flag(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $unit = EquipmentUnit::factory()->create();
        $expired = $this->document($unit, ['document_type' => 'certificado_calibracion', 'valid_until' => now()->subDay()]);
        $soon = $this->document($unit, ['document_type' => 'certificado_calibracion', 'valid_until' => now()->addDays(10)]);
        $later = $this->document($unit, ['document_type' => 'garantia', 'valid_until' => now()->addYear()]);
        $forever = $this->document($unit);

        $url = "/api/equipment-units/{$unit->id}/maintenance-documents";
        $byId = fn ($response) => collect($response->json('data'))->keyBy('id');

        $all = $byId($this->getJson($url)->assertOk()->assertJsonCount(4, 'data'));
        $this->assertTrue($all[$expired->id]['is_expired']);
        $this->assertFalse($all[$forever->id]['is_expired']);

        $this->assertEqualsCanonicalizing([$expired->id, $soon->id], $byId($this->getJson("{$url}?document_type=certificado_calibracion"))->keys()->all());
        $this->getJson("{$url}?expiring_within_days=30")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $soon->id);
        $this->assertEqualsCanonicalizing([$soon->id, $later->id, $forever->id], $byId($this->getJson("{$url}?current=1"))->keys()->all());
        $this->getJson("{$url}?document_type=factura")->assertStatus(422)->assertJsonValidationErrors('document_type');
    }

    public function test_delete_removes_file(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $unit = EquipmentUnit::factory()->create();
        $id = $this->post("/api/equipment-units/{$unit->id}/maintenance-documents", [
            'document_type' => 'manual', 'title' => 'Manual', 'file' => $this->pdf(),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $path = MaintenanceDocument::findOrFail($id)->file_path;

        $this->deleteJson("/api/maintenance-documents/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('maintenance_documents', ['id' => $id]);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_role_permissions(): void
    {
        $unit = EquipmentUnit::factory()->create();
        $document = $this->document($unit);

        $this->actingAsRole(User::ROLE_TEACHER);
        $this->getJson("/api/equipment-units/{$unit->id}/maintenance-documents")->assertForbidden();
        $this->get("/api/maintenance-documents/{$document->id}/file", ['Accept' => 'application/json'])->assertForbidden();

        $this->actingAsRole(User::ROLE_DIRECTOR);
        $this->post("/api/equipment-units/{$unit->id}/maintenance-documents", [
            'document_type' => 'manual', 'title' => 'Manual', 'file' => $this->pdf(),
        ], ['Accept' => 'application/json'])->assertForbidden();
        $this->deleteJson("/api/maintenance-documents/{$document->id}")->assertForbidden();
        // El archivo no existe en el disco falso: 404 para quien sí puede leer.
        $this->get("/api/maintenance-documents/{$document->id}/file", ['Accept' => 'application/json'])->assertNotFound();
    }

    public function test_unit_life_sheet_lists_only_current_documents(): void
    {
        $unit = EquipmentUnit::factory()->create();
        $this->document($unit, ['title' => 'Garantía vigente', 'valid_until' => now()->addYear()]);
        $this->document($unit, ['title' => 'Certificado vencido', 'valid_until' => now()->subMonth()]);

        $html = app(EquipmentPdfService::class)->unitTechnicalSheetView($unit)->render();

        $this->assertStringContainsString('Documentos vigentes (1)', $html);
        $this->assertStringContainsString('Garantía vigente', $html);
        $this->assertStringNotContainsString('Certificado vencido', $html);
    }
}
