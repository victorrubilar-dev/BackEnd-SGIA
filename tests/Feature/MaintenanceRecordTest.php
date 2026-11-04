<?php

namespace Tests\Feature;

use App\Models\EquipmentUnit;
use App\Models\IncidentReport;
use App\Models\MaintenanceRecord;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * REQ-18: registro de mantenciones de unidades de equipo.
 */
class MaintenanceRecordTest extends TestCase
{
    use DatabaseTransactions;

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function unit(array $product = [], array $unit = []): EquipmentUnit
    {
        $product = Product::factory()->create(['quantity' => 0, 'stock_minimo' => 0, ...$product]);

        return EquipmentUnit::factory()->create(['product_id' => $product->id, ...$unit]);
    }

    private function approveChecklist(int $recordId, array $extra = []): void
    {
        $this->patchJson("/api/maintenance-records/{$recordId}", [
            'rev_a_approved' => true,
            'rev_b_approved' => true,
            'pfg_approved' => true,
            ...$extra,
        ])->assertOk();
    }

    public function test_full_flow_with_own_means_and_incident_report(): void
    {
        $user = $this->actingAsRole(User::ROLE_WAREHOUSE);
        $unit = $this->unit(['maintenance_frequency_months' => 6]);
        $report = IncidentReport::factory()->create(['product_id' => $unit->product_id, 'equipment_unit_id' => $unit->id]);

        $id = $this->postJson("/api/equipment-units/{$unit->id}/maintenance-records", [
            'maintenance_type' => 'correctiva',
            'incident_report_id' => $report->id,
            'technical_notes' => 'No enciende',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'en_curso')
            ->assertJsonPath('data.execution_mode', 'medios_propios')
            ->assertJsonPath('data.responsible_id', $user->id)
            ->assertJsonPath('data.equipment_unit.status', 'en_reparacion')
            ->assertJsonPath('data.checklist_complete', false)
            ->assertJsonPath('data.allowed_transitions', ['finalizada', 'anulada'])
            ->json('data.id');

        $this->assertSame('en_reparacion', $report->fresh()->status);
        $this->assertSame(0, $unit->product->fresh()->quantity);
        $this->assertSame(1, $unit->product->fresh()->quantity_in_repair);

        $this->approveChecklist($id, ['repair_cost' => 15000, 'spare_parts_used' => 'Fuente de poder', 'rev_c_approved' => null]);

        $this->postJson("/api/maintenance-records/{$id}/finish", ['result' => 'operativo'])
            ->assertOk()
            ->assertJsonPath('data.status', 'finalizada')
            ->assertJsonPath('data.result', 'operativo')
            ->assertJsonPath('data.repair_cost', '15000.00')
            ->assertJsonPath('data.equipment_unit.status', 'disponible')
            ->assertJsonPath('data.allowed_transitions', []);

        $unit->refresh();
        $this->assertNotNull($unit->last_maintenance_at);
        $this->assertSame(now()->addMonthsNoOverflow(6)->toDateString(), $unit->next_maintenance_at->toDateString());
        $this->assertSame('reparado', $report->fresh()->status);
        $this->assertSame(1, $unit->product->fresh()->quantity);
        $this->assertSame(0, $unit->product->fresh()->quantity_in_repair);
    }

    public function test_external_maintenance_requires_supplier_and_write_off_skips_checklist(): void
    {
        $this->actingAsRole(User::ROLE_ADMIN);
        $unit = $this->unit(['maintenance_frequency_months' => 12]);
        $supplier = Supplier::factory()->create();

        $this->postJson("/api/equipment-units/{$unit->id}/maintenance-records", ['maintenance_type' => 'calibracion', 'execution_mode' => 'externa'])
            ->assertStatus(422)->assertJsonValidationErrors('external_supplier_id');

        $id = $this->postJson("/api/equipment-units/{$unit->id}/maintenance-records", [
            'maintenance_type' => 'calibracion',
            'execution_mode' => 'externa',
            'external_supplier_id' => $supplier->id,
        ])->assertCreated()->assertJsonPath('data.external_supplier.id', $supplier->id)->json('data.id');

        $this->postJson("/api/maintenance-records/{$id}/finish", ['result' => 'baja'])
            ->assertOk()->assertJsonPath('data.result', 'baja')->assertJsonPath('data.equipment_unit.status', 'dado_de_baja');

        $this->assertNull($unit->fresh()->next_maintenance_at);
        $this->assertSame(1, $unit->product->fresh()->quantity_written_off);
    }

    public function test_finish_requires_checklist_for_operational_result(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $unit = $this->unit();
        $id = $this->postJson("/api/equipment-units/{$unit->id}/maintenance-records", ['maintenance_type' => 'preventiva'])->json('data.id');

        $this->postJson("/api/maintenance-records/{$id}/finish", ['result' => 'operativo'])
            ->assertStatus(422)->assertJsonValidationErrors('checklist');

        // REV-C rechazada (no nula) también bloquea.
        $this->approveChecklist($id, ['rev_c_approved' => false]);
        $this->postJson("/api/maintenance-records/{$id}/finish", ['result' => 'operativo'])
            ->assertStatus(422)->assertJsonValidationErrors('checklist');

        $this->postJson("/api/maintenance-records/{$id}/finish", ['result' => 'otro'])
            ->assertStatus(422)->assertJsonValidationErrors('result');

        $this->approveChecklist($id, ['rev_c_approved' => true]);
        $this->postJson("/api/maintenance-records/{$id}/finish", ['result' => 'operativo'])->assertOk();

        // Finalizada: no se edita ni se vuelve a cerrar.
        $this->patchJson("/api/maintenance-records/{$id}", ['technical_notes' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors('status');
        $this->postJson("/api/maintenance-records/{$id}/finish", ['result' => 'operativo'])
            ->assertStatus(422)->assertJsonPath('allowed_transitions', []);
        $this->postJson("/api/maintenance-records/{$id}/cancel")->assertStatus(422);
    }

    public function test_opening_is_rejected_for_unavailable_units_or_foreign_reports(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $onLoan = $this->unit(unit: ['status' => 'prestado']);
        $writtenOff = $this->unit(unit: ['status' => 'dado_de_baja']);
        $unit = $this->unit();
        $foreignReport = IncidentReport::factory()->create(['equipment_unit_id' => $onLoan->id, 'product_id' => $onLoan->product_id]);
        $closedReport = IncidentReport::factory()->create(['equipment_unit_id' => $unit->id, 'product_id' => $unit->product_id, 'status' => 'reparado']);

        $this->postJson("/api/equipment-units/{$onLoan->id}/maintenance-records", ['maintenance_type' => 'preventiva'])
            ->assertStatus(422)->assertJsonValidationErrors('equipment_unit_id');
        $this->postJson("/api/equipment-units/{$writtenOff->id}/maintenance-records", ['maintenance_type' => 'preventiva'])
            ->assertStatus(422)->assertJsonValidationErrors('equipment_unit_id');
        $this->postJson("/api/equipment-units/{$unit->id}/maintenance-records", ['maintenance_type' => 'preventiva', 'incident_report_id' => $foreignReport->id])
            ->assertStatus(422)->assertJsonValidationErrors('incident_report_id');
        $this->postJson("/api/equipment-units/{$unit->id}/maintenance-records", ['maintenance_type' => 'preventiva', 'incident_report_id' => $closedReport->id])
            ->assertStatus(422)->assertJsonValidationErrors('incident_report_id');
        $this->postJson("/api/equipment-units/{$unit->id}/maintenance-records", ['maintenance_type' => 'limpieza'])
            ->assertStatus(422)->assertJsonValidationErrors('maintenance_type');

        $this->postJson("/api/equipment-units/{$unit->id}/maintenance-records", ['maintenance_type' => 'preventiva'])->assertCreated();
        $this->postJson("/api/equipment-units/{$unit->id}/maintenance-records", ['maintenance_type' => 'preventiva'])
            ->assertStatus(422)->assertJsonValidationErrors('equipment_unit_id');
    }

    public function test_cancel_restores_unit_and_report_status(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $unit = $this->unit();
        $report = IncidentReport::factory()->create(['equipment_unit_id' => $unit->id, 'product_id' => $unit->product_id, 'status' => 'en_revision']);

        $id = $this->postJson("/api/equipment-units/{$unit->id}/maintenance-records", ['maintenance_type' => 'correctiva', 'incident_report_id' => $report->id])->json('data.id');

        $this->postJson("/api/maintenance-records/{$id}/cancel", ['reason' => 'Se abrió por error'])
            ->assertOk()->assertJsonPath('data.status', 'anulada')->assertJsonPath('data.equipment_unit.status', 'disponible');
        $this->assertSame('en_revision', $report->fresh()->status);
        $this->assertStringContainsString('Se abrió por error', MaintenanceRecord::find($id)->technical_notes);

        // Una unidad que ya estaba en taller (p. ej. devuelta dañada) sigue en taller al anular.
        $damaged = $this->unit(unit: ['status' => 'en_reparacion']);
        $id = $this->postJson("/api/equipment-units/{$damaged->id}/maintenance-records", ['maintenance_type' => 'correctiva'])->json('data.id');
        $this->postJson("/api/maintenance-records/{$id}/cancel")->assertOk()->assertJsonPath('data.equipment_unit.status', 'en_reparacion');
    }

    public function test_manual_status_change_is_blocked_while_in_progress(): void
    {
        $this->actingAsRole(User::ROLE_WAREHOUSE);
        $unit = $this->unit();
        $this->postJson("/api/equipment-units/{$unit->id}/maintenance-records", ['maintenance_type' => 'preventiva'])->assertCreated();

        $this->patchJson("/api/equipment-units/{$unit->id}/status", ['status' => 'disponible'])
            ->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_listing_filters_and_priority_order(): void
    {
        $this->actingAsRole(User::ROLE_DIRECTOR);
        $low = $this->unit(['maintenance_priority' => 'C']);
        $high = $this->unit(['maintenance_priority' => 'A']);
        $lowRecord = MaintenanceRecord::factory()->create(['equipment_unit_id' => $low->id, 'started_at' => now()]);
        $highRecord = MaintenanceRecord::factory()->create(['equipment_unit_id' => $high->id, 'started_at' => now()->subDays(10), 'maintenance_type' => 'calibracion']);
        MaintenanceRecord::factory()->create(['equipment_unit_id' => $low->id, 'status' => 'finalizada', 'started_at' => now()->subYear()]);

        $ids = collect($this->getJson("/api/maintenance-records?equipment_unit_id={$low->id}")->assertOk()->json('data'))->pluck('id');
        $this->assertCount(2, $ids);

        $this->getJson("/api/maintenance-records?status=en_curso&product_id={$high->product_id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $highRecord->id);
        $this->getJson('/api/maintenance-records?maintenance_type=calibracion&execution_mode=medios_propios&from='.now()->subMonth()->toDateString())
            ->assertOk()->assertJsonPath('data.0.id', $highRecord->id);

        $ordered = collect($this->getJson('/api/maintenance-records?status=en_curso&per_page=100')->json('data'))->pluck('id');
        $this->assertLessThan($ordered->search($lowRecord->id), $ordered->search($highRecord->id));

        $this->getJson('/api/maintenance-records?from=no-es-fecha')->assertStatus(422)->assertJsonValidationErrors('from');

        $this->getJson("/api/equipment-units/{$low->id}/maintenance-records")->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $lowRecord->id);
        $this->getJson("/api/maintenance-records/{$lowRecord->id}")->assertOk()->assertJsonPath('data.equipment_unit.id', $low->id);
    }

    public function test_role_permissions(): void
    {
        $unit = $this->unit();
        $record = MaintenanceRecord::factory()->create(['equipment_unit_id' => $unit->id]);

        $this->actingAsRole(User::ROLE_TEACHER);
        $this->getJson('/api/maintenance-records')->assertForbidden();
        $this->getJson("/api/equipment-units/{$unit->id}/maintenance-records")->assertForbidden();
        $this->postJson("/api/equipment-units/{$unit->id}/maintenance-records", ['maintenance_type' => 'preventiva'])->assertForbidden();

        $this->actingAsRole(User::ROLE_DIRECTOR);
        $this->getJson("/api/maintenance-records/{$record->id}")->assertOk();
        $this->postJson("/api/equipment-units/{$unit->id}/maintenance-records", ['maintenance_type' => 'preventiva'])->assertForbidden();
        $this->patchJson("/api/maintenance-records/{$record->id}", ['technical_notes' => 'x'])->assertForbidden();
        $this->postJson("/api/maintenance-records/{$record->id}/finish", ['result' => 'baja'])->assertForbidden();
        $this->postJson("/api/maintenance-records/{$record->id}/cancel")->assertForbidden();
    }
}
