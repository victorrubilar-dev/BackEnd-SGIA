<?php

namespace Tests\Feature;

use App\Models\EquipmentUnit;
use App\Models\IncidentReport;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use App\Notifications\IncidentReportNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * REQ-13: informe de novedades del docente (formulario con problemas marcados, foto, equipo por código y firma).
 */
class IncidentReportTest extends TestCase
{
    use DatabaseTransactions;

    /** PNG de 1x1 píxel. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected User $teacher;

    protected User $director;

    protected User $warehouse;

    protected User $admin;

    protected Location $lab;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->teacher = User::factory()->create(['role' => User::ROLE_TEACHER, 'is_active' => true, 'name' => 'Prof. Soto']);
        $this->director = User::factory()->create(['role' => User::ROLE_DIRECTOR, 'is_active' => true]);
        $this->warehouse = User::factory()->create(['role' => User::ROLE_WAREHOUSE, 'is_active' => true]);
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        $this->lab = Location::factory()->create();
    }

    /** @param array<string, mixed> $override */
    private function payload(array $override = []): array
    {
        return $override + [
            'subject' => 'Redes de Computadores',
            'location_id' => $this->lab->id,
            'problems' => [
                ['type' => 'falla_equipo', 'description' => 'El switch no enciende.'],
                ['type' => 'falta_insumo', 'description' => 'Faltan cables UTP.'],
            ],
            'suggestion' => 'Reponer antes de la prueba.',
            'signature' => 'data:image/png;base64,'.self::PNG,
        ];
    }

    public function test_guest_cannot_register_incident_report(): void
    {
        $this->postJson('/api/incident-reports', $this->payload())->assertUnauthorized();
    }

    public function test_teacher_registers_the_form_with_signature_and_it_is_stored_privately(): void
    {
        Notification::fake();
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/incident-reports', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'reportado')
            ->assertJsonPath('data.teacher_name', 'Prof. Soto')
            ->assertJsonPath('data.subject', 'Redes de Computadores')
            ->assertJsonPath('data.report_date', today()->toDateString())
            ->assertJsonPath('data.location_id', $this->lab->id)
            ->assertJsonPath('data.problems.0.type', 'falla_equipo')
            ->assertJsonPath('data.problems.0.label', 'Falla de equipos / instrumento / software')
            ->assertJsonPath('data.problems.1.description', 'Faltan cables UTP.')
            ->assertJsonPath('data.suggestion', 'Reponer antes de la prueba.')
            ->assertJsonPath('data.has_signature', true)
            ->assertJsonPath('data.is_legacy', false)
            ->assertJsonPath('data.product_id', null)
            ->assertJsonPath('data.allowed_transitions', ['en_revision', 'resuelto', 'descartado']);

        $report = IncidentReport::findOrFail($response->json('data.id'));
        $this->assertSame($this->teacher->id, $report->reported_by);
        Storage::disk('local')->assertExists($report->signature_path);
        Storage::disk('public')->assertMissing($report->signature_path);
        $this->assertStringStartsWith('NV-', $report->code);

        // Avisa a pañol, administración y dirección (no al docente).
        Notification::assertSentTo([$this->warehouse, $this->admin, $this->director], IncidentReportNotification::class);
        Notification::assertNotSentTo($this->teacher, IncidentReportNotification::class);
    }

    public function test_form_validation(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/incident-reports', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['subject', 'location_id', 'problems', 'signature']);

        $this->postJson('/api/incident-reports', $this->payload(['problems' => [['type' => 'otros']]]))
            ->assertStatus(422)->assertJsonValidationErrors(['problems.0.description']);
        $this->postJson('/api/incident-reports', $this->payload(['problems' => [['type' => 'inventado', 'description' => 'x']]]))
            ->assertStatus(422)->assertJsonValidationErrors(['problems.0.type']);
        $this->postJson('/api/incident-reports', $this->payload(['problems' => [
            ['type' => 'otros', 'description' => 'a'], ['type' => 'otros', 'description' => 'b'],
        ]]))->assertStatus(422)->assertJsonValidationErrors(['problems.0.type']);
        $this->postJson('/api/incident-reports', $this->payload(['location_id' => 999999]))
            ->assertStatus(422)->assertJsonValidationErrors('location_id');
        $this->postJson('/api/incident-reports', $this->payload(['report_date' => today()->addDay()->toDateString()]))
            ->assertStatus(422)->assertJsonValidationErrors('report_date');
        $this->postJson('/api/incident-reports', $this->payload(['signature' => base64_encode('no soy un png')]))
            ->assertStatus(422)->assertJsonValidationErrors('signature');
        $this->postJson('/api/incident-reports', $this->payload(['evidence_photo' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')]))
            ->assertStatus(422)->assertJsonValidationErrors('evidence_photo');
    }

    public function test_only_teachers_can_register_reports(): void
    {
        foreach ([$this->warehouse, $this->director, $this->admin] as $user) {
            Sanctum::actingAs($user);
            $this->postJson('/api/incident-reports', $this->payload())->assertForbidden();
        }

        // El endpoint por equipo ya no existe.
        Sanctum::actingAs($this->teacher);
        $this->postJson('/api/equipment/'.Product::factory()->supply()->create()->id.'/reports', [])->assertStatus(405);
    }

    public function test_past_date_and_optional_photo_are_accepted(): void
    {
        Sanctum::actingAs($this->teacher);

        $id = $this->postJson('/api/incident-reports', $this->payload([
            'report_date' => today()->subDays(3)->toDateString(),
            'evidence_photo' => UploadedFile::fake()->create('foto.jpg', 100, 'image/jpeg'),
        ]))->assertCreated()
            ->assertJsonPath('data.report_date', today()->subDays(3)->toDateString())
            ->assertJsonPath('data.has_evidence_photo', true)
            ->json('data.id');

        $report = IncidentReport::findOrFail($id);
        Storage::disk('local')->assertExists($report->evidence_photo_path);
        Storage::disk('public')->assertMissing($report->evidence_photo_path);
    }

    public function test_equipment_is_associated_by_unit_code_or_supply_barcode(): void
    {
        $unit = EquipmentUnit::factory()->create();
        $supply = Product::factory()->supply()->create();
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/incident-reports', $this->payload(['equipment_code' => $unit->code]))
            ->assertCreated()
            ->assertJsonPath('data.equipment_unit_id', $unit->id)
            ->assertJsonPath('data.product_id', $unit->product_id)
            ->assertJsonPath('data.equipment_unit.code', $unit->code);

        $this->postJson('/api/incident-reports', $this->payload(['equipment_code' => $supply->barcode]))
            ->assertCreated()
            ->assertJsonPath('data.product_id', $supply->id)
            ->assertJsonPath('data.equipment_unit_id', null);

        $this->postJson('/api/incident-reports', $this->payload(['equipment_code' => 'NO-EXISTE-1']))
            ->assertStatus(422)->assertJsonValidationErrors('equipment_code');
        $this->postJson('/api/incident-reports', $this->payload(['equipment_code' => $unit->product->barcode]))
            ->assertStatus(422)->assertJsonValidationErrors('equipment_code');

    }

    public function test_teacher_sees_only_their_reports_pdf_and_signature(): void
    {
        Sanctum::actingAs($this->teacher);
        $id = $this->postJson('/api/incident-reports', $this->payload())->assertCreated()->json('data.id');
        $other = IncidentReport::factory()->teacherForm()->create();

        $this->getJson('/api/incident-reports/mine')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->getJson("/api/incident-reports/$id")->assertOk()->assertJsonPath('data.location.id', $this->lab->id);
        $this->get("/api/incident-reports/$id/signature")->assertOk()->assertDownload();

        $pdf = $this->get("/api/incident-reports/$id/pdf");
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());

        // Ajeno: 403 en detalle, PDF y firma.
        $this->getJson("/api/incident-reports/{$other->id}")->assertForbidden();
        $this->get("/api/incident-reports/{$other->id}/pdf")->assertForbidden();
        $this->get("/api/incident-reports/{$other->id}/signature")->assertForbidden();

        // El personal ve cualquiera; el listado de "mis informes" es solo del docente.
        foreach ([$this->warehouse, $this->admin, $this->director] as $user) {
            Sanctum::actingAs($user);
            $this->getJson("/api/incident-reports/$id")->assertOk();
            $this->get("/api/incident-reports/$id/pdf")->assertOk();
            $this->getJson('/api/incident-reports/mine')->assertForbidden();
        }
    }

    public function test_staff_attends_the_report_and_the_teacher_is_notified(): void
    {
        $report = IncidentReport::factory()->teacherForm()->create(['reported_by' => $this->teacher->id]);
        Notification::fake();

        Sanctum::actingAs($this->teacher);
        $this->patchJson("/api/incident-reports/{$report->id}/status", ['status' => 'en_revision'])->assertForbidden();

        Sanctum::actingAs($this->warehouse);
        $this->patchJson("/api/incident-reports/{$report->id}/status", ['status' => 'reparado'])->assertUnprocessable();
        $this->patchJson("/api/incident-reports/{$report->id}/status", ['status' => 'en_revision'])
            ->assertOk()->assertJsonPath('data.status', 'en_revision');
        Notification::assertSentTo($this->teacher, IncidentReportNotification::class);

        Sanctum::actingAs($this->director);
        $this->patchJson("/api/incident-reports/{$report->id}/status", ['status' => 'resuelto'])->assertOk();
        // Estado final
        $this->patchJson("/api/incident-reports/{$report->id}/status", ['status' => 'en_revision'])->assertUnprocessable();
    }

    public function test_legacy_reports_keep_their_statuses_attachment_and_staff_only_access(): void
    {
        $this->assertTrue(IncidentReport::factory()->create()->isLegacy());
        $product = Product::factory()->supply()->create();
        Storage::disk('local')->put('incidents/falla.pdf', 'x');
        $legacy = IncidentReport::factory()->create([
            'product_id' => $product->id, 'attachment' => 'incidents/falla.pdf', 'attachment_original_name' => 'falla.pdf',
        ]);

        Sanctum::actingAs($this->teacher);
        $this->get("/api/incident-reports/{$legacy->id}/attachment")->assertForbidden();

        Sanctum::actingAs($this->director);
        $this->get("/api/incident-reports/{$legacy->id}/attachment")->assertOk()->assertDownload('falla.pdf');
        $this->patchJson("/api/incident-reports/{$legacy->id}/status", ['status' => 'en_revision'])->assertOk();
        $this->patchJson("/api/incident-reports/{$legacy->id}/status", ['status' => 'reparado'])->assertOk();
        $this->patchJson("/api/incident-reports/{$legacy->id}/status", ['status' => 'en_revision'])->assertUnprocessable();
        $this->patchJson("/api/incident-reports/{$legacy->id}/status", ['status' => 'inventado'])->assertUnprocessable();

        $noAttachment = IncidentReport::factory()->create();
        $this->getJson("/api/incident-reports/{$noAttachment->id}/attachment")->assertNotFound();
        $this->getJson("/api/incident-reports/{$noAttachment->id}/signature")->assertNotFound();
    }

    public function test_global_list_filters_and_is_restricted_to_staff(): void
    {
        $product = Product::factory()->supply()->create();
        $critical = IncidentReport::factory()->create(['product_id' => $product->id, 'severity' => IncidentReport::SEVERITY_HIGH, 'title' => 'Pantalla rota global']);
        IncidentReport::factory()->create(['severity' => IncidentReport::SEVERITY_LOW, 'title' => 'Otro asunto']);

        Sanctum::actingAs($this->director);

        $ids = collect($this->getJson('/api/incident-reports?severity=critica&search=global&per_page=100')->assertOk()->json('data'))->pluck('id');
        $this->assertSame([$critical->id], $ids->all());
        $this->getJson('/api/incident-reports?product_id='.$product->id)->assertOk()->assertJsonPath('data.0.product.id', $product->id);

        Sanctum::actingAs($this->teacher);
        $this->getJson('/api/incident-reports')->assertForbidden();
    }
}
