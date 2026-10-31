<?php

namespace Tests\Feature;

use App\Models\IncidentReport;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IncidentReportTest extends TestCase
{
    use DatabaseTransactions;

    protected User $teacher;

    protected User $director;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->teacher = User::factory()->create([
            'email' => 'pro_nov_'.uniqid().'@inacap.cl',
            'role' => User::ROLE_TEACHER,
            'is_active' => true,
        ]);

        $this->director = User::factory()->create([
            'email' => 'dir_nov_'.uniqid().'@inacap.cl',
            'role' => User::ROLE_DIRECTOR,
            'is_active' => true,
        ]);

        $this->product = Product::factory()->supply()->create([
            'name' => 'Osciloscopio Rigol',
        ]);
    }

    public function test_guest_cannot_register_incident_report(): void
    {
        $this->postJson("/api/equipment/{$this->product->id}/reports", [
            'description' => 'El osciloscopio no enciende.',
        ])->assertStatus(401);
    }

    public function test_teacher_can_register_incident_report_on_equipment(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson("/api/equipment/{$this->product->id}/reports", [
            'title' => 'No enciende',
            'description' => 'El osciloscopio no enciende despues de conectar la fuente.',
            'severity' => 'critica',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'message',
            'data' => ['id', 'code', 'product_id', 'severity', 'severity_label', 'status', 'created_at'],
        ]);
        $response->assertJsonPath('data.product_id', $this->product->id);
        $response->assertJsonPath('data.severity', 'critica');
        $response->assertJsonPath('data.status', 'reportado');
        $this->assertStringStartsWith('NV-', (string) $response->json('data.code'));

        // Trazabilidad: el informe queda asociado al equipo (hoja de vida).
        $this->assertDatabaseHas('incident_reports', [
            'product_id' => $this->product->id,
            'reported_by' => $this->teacher->id,
            'severity' => 'critica',
            'status' => 'reportado',
        ]);
    }

    public function test_incident_report_accepts_optional_attachment(): void
    {
        Sanctum::actingAs($this->teacher);

        $file = UploadedFile::fake()->create('informe_falla.pdf', 120, 'application/pdf');

        $response = $this->postJson("/api/equipment/{$this->product->id}/reports", [
            'description' => 'Documento tecnico del fabricante sobre la falla de la pantalla.',
            'attachment' => $file,
        ]);

        $response->assertStatus(201);

        $attachment = IncidentReport::where('product_id', $this->product->id)->first()?->attachment;

        $this->assertNotNull($attachment);
        $this->assertStringStartsWith('incidents/', $attachment);
        Storage::disk('local')->assertExists($attachment);
        $response->assertJsonPath('data.attachment_original_name', 'informe_falla.pdf');
        $this->assertStringEndsWith('/attachment', (string) $response->json('data.attachment_url'));
    }

    public function test_incident_report_accepts_spanish_field_aliases(): void
    {
        Sanctum::actingAs($this->teacher);

        $file = UploadedFile::fake()->create('foto_falla.jpg', 50, 'image/jpeg');

        $response = $this->postJson("/api/equipment/{$this->product->id}/reports", [
            'titulo' => 'Cable de prueba dañado',
            'descripcion' => 'El cable de prueba tiene la punta partida y no hace contacto.',
            'gravedad' => 'media',
            'archivo' => $file,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.severity', 'media');

        $report = IncidentReport::where('product_id', $this->product->id)->first();

        $this->assertSame('Cable de prueba dañado', $report?->title);
        $this->assertSame('foto_falla.jpg', $report?->attachment_original_name);
        Storage::disk('local')->assertExists((string) $report?->attachment);
    }

    public function test_registered_report_appears_in_equipment_life_sheet(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson("/api/equipment/{$this->product->id}/reports", [
            'title' => 'Falla intermitente de pantalla',
            'description' => 'La pantalla parpadea cuando se mueve el equipo.',
        ])->assertStatus(201);

        // Listado de informes del equipo (REQ-12).
        Sanctum::actingAs($this->director);
        $list = $this->getJson("/api/equipment/{$this->product->id}/reports");

        $list->assertStatus(200);
        $list->assertJsonCount(1, 'data');
        $list->assertJsonPath('data.0.title', 'Falla intermitente de pantalla');
        $list->assertJsonPath('data.0.reporter.id', $this->teacher->id);

        // La ficha técnica (hoja de vida) incluye la novedad.
        $pdf = $this->get("/api/equipment/{$this->product->id}/technical-sheet");
        $pdf->assertStatus(200);
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());
    }

    public function test_report_with_title_only_is_accepted(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson("/api/equipment/{$this->product->id}/reports", [
            'title' => 'Ruido excesivo en ventilador',
        ])->assertStatus(201);
    }

    public function test_report_requires_description_or_title(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson("/api/equipment/{$this->product->id}/reports", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['description']);
    }

    public function test_report_rejects_invalid_severity(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson("/api/equipment/{$this->product->id}/reports", [
            'description' => 'Novedad reportada con una severidad fuera del dominio admitido.',
            'severity' => 'apocaliptica',
        ])->assertStatus(422)->assertJsonValidationErrors(['severity']);
    }

    public function test_report_rejects_invalid_and_oversized_attachments(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson("/api/equipment/{$this->product->id}/reports", [
            'description' => 'Adjunto con un tipo de archivo no admitido por el sistema.',
            'attachment' => UploadedFile::fake()->create('programa.exe', 10),
        ])->assertStatus(422)->assertJsonValidationErrors(['attachment']);

        $this->postJson("/api/equipment/{$this->product->id}/reports", [
            'description' => 'Adjunto que supera el limite de 10 megabytes permitido.',
            'attachment' => UploadedFile::fake()->create('grande.pdf', 11000, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors(['attachment']);
    }

    public function test_director_cannot_register_incident_report(): void
    {
        Sanctum::actingAs($this->director);

        $this->postJson("/api/equipment/{$this->product->id}/reports", [
            'description' => 'El director no registra novedades directamente en el sistema.',
        ])->assertStatus(403);
    }

    public function test_warehouse_can_register_incident_report(): void
    {
        $warehouse = User::factory()->create([
            'email' => 'pan_nov_'.uniqid().'@inacap.cl',
            'role' => User::ROLE_WAREHOUSE,
            'is_active' => true,
        ]);

        Sanctum::actingAs($warehouse);

        $this->postJson("/api/equipment/{$this->product->id}/reports", [
            'description' => 'El pañol detecta desgaste en el cable de alimentación del equipo.',
        ])->assertStatus(201);
    }

    public function test_report_for_unknown_equipment_returns_404(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/equipment/9999999/reports', [
            'description' => 'No existe el equipo al que se asocia este informe.',
        ])->assertStatus(404);
    }

    public function test_attachment_is_private_and_downloadable_only_by_authorized_roles(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson("/api/equipment/{$this->product->id}/reports", [
            'description' => 'Pantalla con lineas verticales en el equipo.',
            'attachment' => UploadedFile::fake()->create('falla.pdf', 50, 'application/pdf'),
        ])->assertCreated();

        $report = IncidentReport::where('product_id', $this->product->id)->firstOrFail();

        Storage::disk('local')->assertExists($report->attachment);
        Storage::disk('public')->assertMissing($report->attachment);

        // El docente que lo reportó no puede ver informes (solo AD/DIR/PAN).
        $this->get("/api/incident-reports/{$report->id}/attachment")->assertForbidden();

        Sanctum::actingAs($this->director);
        $this->get("/api/incident-reports/{$report->id}/attachment")
            ->assertOk()
            ->assertDownload('falla.pdf');
    }

    public function test_attachment_download_returns_404_when_report_has_no_attachment(): void
    {
        Sanctum::actingAs($this->teacher);
        $id = $this->postJson("/api/equipment/{$this->product->id}/reports", [
            'description' => 'Sin adjunto en este informe de prueba.',
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($this->director);
        $this->getJson("/api/incident-reports/{$id}/attachment")->assertNotFound();
    }

    public function test_global_list_filters_and_is_restricted_to_staff(): void
    {
        $critical = IncidentReport::factory()->create(['product_id' => $this->product->id, 'severity' => IncidentReport::SEVERITY_HIGH, 'title' => 'Pantalla rota global']);
        IncidentReport::factory()->create(['severity' => IncidentReport::SEVERITY_LOW, 'title' => 'Otro asunto']);

        Sanctum::actingAs($this->director);

        $ids = collect($this->getJson('/api/incident-reports?severity=critica&search=global&per_page=100')->assertOk()->json('data'))->pluck('id');
        $this->assertSame([$critical->id], $ids->all());

        $this->getJson('/api/incident-reports?product_id='.$this->product->id)->assertOk()->assertJsonPath('data.0.product.id', $this->product->id);

        Sanctum::actingAs($this->teacher);
        $this->getJson('/api/incident-reports')->assertForbidden();
    }

    public function test_status_follows_allowed_transitions_and_roles(): void
    {
        $report = IncidentReport::factory()->create(['product_id' => $this->product->id, 'status' => IncidentReport::STATUS_REPORTED]);

        Sanctum::actingAs($this->teacher);
        $this->patchJson("/api/incident-reports/{$report->id}/status", ['status' => 'en_revision'])->assertForbidden();

        Sanctum::actingAs($this->director);
        $this->patchJson("/api/incident-reports/{$report->id}/status", ['status' => 'reparado'])->assertUnprocessable();
        $this->patchJson("/api/incident-reports/{$report->id}/status", ['status' => 'inventado'])->assertUnprocessable();

        $this->patchJson("/api/incident-reports/{$report->id}/status", ['status' => 'en_revision'])
            ->assertOk()->assertJsonPath('data.status', 'en_revision');
        $this->patchJson("/api/incident-reports/{$report->id}/status", ['status' => 'reparado'])->assertOk();

        // Estado final: no admite más cambios
        $this->patchJson("/api/incident-reports/{$report->id}/status", ['status' => 'en_revision'])->assertUnprocessable();
    }
}
