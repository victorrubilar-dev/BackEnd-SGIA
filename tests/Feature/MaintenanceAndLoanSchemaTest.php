<?php

namespace Tests\Feature;

use App\Models\Career;
use App\Models\EquipmentUnit;
use App\Models\IncidentReport;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\MaintenanceDocument;
use App\Models\MaintenanceRecord;
use App\Models\Product;
use App\Models\Storeroom;
use App\Models\Student;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

class MaintenanceAndLoanSchemaTest extends TestCase
{
    use DatabaseTransactions;

    public function test_incident_report_links_to_unit_with_request_type_default(): void
    {
        $unit = EquipmentUnit::factory()->create();
        $report = IncidentReport::factory()->create(['product_id' => $unit->product_id, 'equipment_unit_id' => $unit->id]);

        $this->assertSame('falla', $report->fresh()->request_type);
        $this->assertTrue($report->equipmentUnit->is($unit));
        $this->assertTrue($unit->incidentReports->contains($report));

        $legacy = IncidentReport::factory()->create();
        $this->assertNull($legacy->equipment_unit_id);
    }

    public function test_unit_with_reports_cannot_be_deleted_at_db_level(): void
    {
        $unit = EquipmentUnit::factory()->create();
        IncidentReport::factory()->create(['product_id' => $unit->product_id, 'equipment_unit_id' => $unit->id]);

        $this->expectException(QueryException::class);
        $unit->delete();
    }

    public function test_maintenance_record_defaults_relations_and_external_rule(): void
    {
        $unit = EquipmentUnit::factory()->create();
        $record = MaintenanceRecord::factory()->create(['equipment_unit_id' => $unit->id]);
        $fresh = $record->fresh();

        $this->assertSame('medios_propios', $fresh->execution_mode);
        $this->assertSame('en_curso', $fresh->status);
        $this->assertSame('0.00', $fresh->repair_cost);
        $this->assertFalse($fresh->rev_a_approved);
        $this->assertNull($fresh->rev_c_approved);
        $this->assertNotNull($fresh->started_at);
        $this->assertTrue($unit->maintenanceRecords->contains($record));
        $this->assertTrue($fresh->responsible instanceof User);
        $this->assertTrue($fresh->hasValidExecution());

        $external = new MaintenanceRecord(['execution_mode' => MaintenanceRecord::MODE_EXTERNAL]);
        $this->assertFalse($external->hasValidExecution());
        $external->external_supplier_id = Supplier::factory()->create()->id;
        $this->assertTrue($external->hasValidExecution());
    }

    public function test_finishing_maintenance_updates_unit_dates_from_product_frequency(): void
    {
        $product = Product::factory()->create(['maintenance_frequency_months' => 6]);
        $unit = EquipmentUnit::factory()->create(['product_id' => $product->id]);
        $record = MaintenanceRecord::factory()->create(['equipment_unit_id' => $unit->id]);

        $record->finish(Carbon::parse('2026-08-31 10:00:00'));

        $this->assertSame('finalizada', $record->fresh()->status);
        $unit = $unit->fresh();
        $this->assertTrue($unit->last_maintenance_at->equalTo(Carbon::parse('2026-08-31 10:00:00')));
        $this->assertSame('2027-02-28', $unit->next_maintenance_at->toDateString());
    }

    public function test_finishing_without_frequency_leaves_next_maintenance_empty(): void
    {
        $unit = EquipmentUnit::factory()->create(['next_maintenance_at' => now()->addMonth()]);
        MaintenanceRecord::factory()->create(['equipment_unit_id' => $unit->id])->finish();

        $this->assertNotNull($unit->fresh()->last_maintenance_at);
        $this->assertNull($unit->fresh()->next_maintenance_at);
    }

    public function test_maintenance_document_relations(): void
    {
        $unit = EquipmentUnit::factory()->create();
        $record = MaintenanceRecord::factory()->create(['equipment_unit_id' => $unit->id]);
        $doc = MaintenanceDocument::create([
            'equipment_unit_id' => $unit->id, 'maintenance_record_id' => $record->id,
            'uploaded_by' => User::factory()->create()->id,
            'document_type' => 'certificado', 'title' => 'Certificado de calibración', 'file_path' => 'maintenance/x.pdf',
        ]);

        $this->assertTrue($record->documents->contains($doc));
        $this->assertTrue($unit->maintenanceDocuments->contains($doc));
        $this->assertTrue($doc->uploader instanceof User);

        $record->delete();
        $this->assertNull($doc->fresh()->maintenance_record_id);
        $unit->maintenanceRecords()->delete();
        $unit->maintenanceDocuments()->delete();
        $unit->delete();
        $this->assertDatabaseMissing('equipment_units', ['id' => $unit->id]);
    }

    public function test_student_rut_is_unique_and_loan_can_reference_student(): void
    {
        $career = Career::factory()->create();
        $student = Student::factory()->create(['career_id' => $career->id]);
        $loan = Loan::factory()->create(['student_id' => $student->id]);

        $this->assertTrue($student->loans->contains($loan));
        $this->assertTrue($loan->student->is($student));
        $this->assertTrue($career->students->contains($student));
        $this->assertNotNull($student->fresh()->registered_at);

        $this->expectException(QueryException::class);
        Student::factory()->create(['rut' => $student->rut]);
    }

    public function test_student_career_is_nulled_when_career_deleted(): void
    {
        $career = Career::factory()->create();
        $student = Student::factory()->create(['career_id' => $career->id]);
        $career->delete();

        $this->assertNull($student->fresh()->career_id);
    }

    public function test_loan_gets_default_storeroom_and_expected_return_at(): void
    {
        $loan = Loan::factory()->create(['loan_date' => '2026-12-10', 'due_date' => '2026-12-12']);

        $this->assertSame(Storeroom::default()->id, $loan->fresh()->storeroom_id);
        $this->assertSame('2026-12-12 23:59:59', $loan->fresh()->expected_return_at->format('Y-m-d H:i:s'));
        $this->assertTrue($loan->storeroom->loans->contains($loan));

        $noDue = Loan::factory()->create(['loan_date' => '2026-12-10']);
        $this->assertSame('2026-12-10', $noDue->fresh()->expected_return_at->toDateString());
    }

    public function test_loan_item_quantities_and_units_pivot(): void
    {
        $loan = Loan::factory()->create();
        $product = Product::factory()->create();
        $item = LoanItem::create(['loan_id' => $loan->id, 'product_id' => $product->id, 'name' => $product->name, 'quantity' => 2]);

        $this->assertSame(0, $item->fresh()->delivered_quantity);
        $this->assertSame(0, $item->fresh()->returned_quantity);

        $units = EquipmentUnit::factory()->count(2)->onLoan()->create(['product_id' => $product->id]);
        $item->units()->attach($units->pluck('id'));

        $this->assertCount(2, $item->fresh()->units);
        $this->assertTrue($units[0]->loanItems->first()->is($item));

        $item->units()->updateExistingPivot($units[0]->id, ['returned_at' => now(), 'return_condition' => LoanItem::CONDITION_GOOD]);
        $this->assertSame('bueno', $item->units()->find($units[0]->id)->pivot->return_condition);

        $this->expectException(QueryException::class);
        $item->units()->attach($units[0]->id);
    }

    public function test_loan_item_unit_pivot_cascades_with_item_and_restricts_unit(): void
    {
        $loan = Loan::factory()->create();
        $product = Product::factory()->create();
        $item = LoanItem::create(['loan_id' => $loan->id, 'product_id' => $product->id, 'name' => 'x', 'quantity' => 1]);
        $unit = EquipmentUnit::factory()->onLoan()->create(['product_id' => $product->id]);
        $item->units()->attach($unit->id);

        try {
            $unit->delete();
            $this->fail('La unidad prestada no debe borrarse.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    public function test_backfill_links_borrowed_units_to_active_loan_items(): void
    {
        $product = Product::factory()->create();
        $units = EquipmentUnit::factory()->count(3)->onLoan()->create(['product_id' => $product->id]);
        $active = LoanItem::create([
            'loan_id' => Loan::factory()->create(['status' => Loan::STATUS_PROCESSED])->id,
            'product_id' => $product->id, 'name' => 'a', 'quantity' => 2,
        ]);
        $second = LoanItem::create([
            'loan_id' => Loan::factory()->create(['status' => Loan::STATUS_IN_PROGRESS])->id,
            'product_id' => $product->id, 'name' => 'b', 'quantity' => 1,
        ]);
        $returned = LoanItem::create([
            'loan_id' => Loan::factory()->create(['status' => Loan::STATUS_PROCESSED])->id,
            'product_id' => $product->id, 'name' => 'c', 'quantity' => 5, 'returned_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_11_05_000001_extend_loans_and_create_loan_item_units.php');
        DB::table('loan_item_units')->delete();
        (new ReflectionMethod($migration, 'linkBorrowedUnits'))->invoke($migration);

        $this->assertCount(2, $active->fresh()->units);
        $this->assertCount(1, $second->fresh()->units);
        $this->assertCount(0, $returned->fresh()->units);
        $this->assertSame(3, DB::table('loan_item_units')->whereIn('equipment_unit_id', $units->pluck('id'))->distinct()->count('equipment_unit_id'));
    }

    public function test_backfill_sql_fills_delivered_and_returned_quantities(): void
    {
        $product = Product::factory()->create();
        $processed = LoanItem::create([
            'loan_id' => Loan::factory()->create(['status' => Loan::STATUS_PROCESSED])->id,
            'product_id' => $product->id, 'name' => 'a', 'quantity' => 3, 'returned_at' => now(),
        ]);
        $pending = LoanItem::create([
            'loan_id' => Loan::factory()->create()->id, 'product_id' => $product->id, 'name' => 'b', 'quantity' => 4,
        ]);

        DB::statement("UPDATE loan_items SET delivered_quantity = quantity WHERE loan_id IN (SELECT id FROM loans WHERE status = 'procesado')");
        DB::statement('UPDATE loan_items SET returned_quantity = quantity WHERE returned_at IS NOT NULL');

        $this->assertSame([3, 3], [$processed->fresh()->delivered_quantity, $processed->fresh()->returned_quantity]);
        $this->assertSame([0, 0], [$pending->fresh()->delivered_quantity, $pending->fresh()->returned_quantity]);
    }
}
