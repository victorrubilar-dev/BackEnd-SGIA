<?php

namespace Tests\Feature;

use App\Http\Resources\AnnualBudgetResource;
use App\Http\Resources\AuditLogResource;
use App\Http\Resources\BudgetMovementResource;
use App\Http\Resources\BudgetSummaryResource;
use App\Http\Resources\CajonResource;
use App\Http\Resources\CareerResource;
use App\Http\Resources\ChartTemplateResource;
use App\Http\Resources\EquipmentUnitResource;
use App\Http\Resources\IncidentReportResource;
use App\Http\Resources\InvoiceIntakeItemResource;
use App\Http\Resources\InvoiceIntakeResource;
use App\Http\Resources\LoanItemResource;
use App\Http\Resources\LoanResource;
use App\Http\Resources\LocationResource;
use App\Http\Resources\MaintenanceDocumentResource;
use App\Http\Resources\MaintenanceRecordResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\PurchaseItemResource;
use App\Http\Resources\PurchaseResource;
use App\Http\Resources\QuotationItemResource;
use App\Http\Resources\QuotationResource;
use App\Http\Resources\QuotationSupplierResource;
use App\Http\Resources\StockAlertResource;
use App\Http\Resources\StudentResource;
use App\Http\Resources\SupplierResource;
use App\Http\Resources\UserChartResource;
use App\Http\Resources\UserResource;
use App\Models\AnnualBudget;
use App\Models\AuditLog;
use App\Models\BudgetMovement;
use App\Models\Cajon;
use App\Models\Career;
use App\Models\ChartTemplate;
use App\Models\EquipmentUnit;
use App\Models\IncidentReport;
use App\Models\InvoiceIntake;
use App\Models\InvoiceIntakeItem;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\Location;
use App\Models\MaintenanceDocument;
use App\Models\MaintenanceRecord;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Quotation;
use App\Models\StockAlert;
use App\Models\Student;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserChart;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Fija las claves que el frontend consume de cada Resource (sin relaciones
 * cargadas). Si un cambio añade, renombra o quita un campo, este test falla y
 * obliga a actualizar `TASKS/front-end.md` y a avisar al cliente.
 */
class ResourceContractTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @param  array<int, string>  $expected
     */
    private function assertKeys(string $resource, object $model, array $expected): void
    {
        $actual = array_keys((new $resource($model))->resolve(request()));
        sort($actual);
        sort($expected);

        $this->assertSame($expected, $actual, "Contrato de {$resource}");
    }

    public function test_user_and_catalog_resources(): void
    {
        $this->assertKeys(UserResource::class, User::factory()->create(), explode(',', 'area,created_at,email,id,is_active,last_login_at,name,role,storeroom_id,updated_at'));
        $this->assertKeys(SupplierResource::class, Supplier::factory()->create(), explode(',', 'address,category,contact_name,created_at,email,id,is_active,name,phone,rut,updated_at'));

        $location = Location::factory()->create();
        $this->assertKeys(LocationResource::class, $location, explode(',', 'building,cajon,created_at,created_by,descripcion,floor,id,nombre,sala,storeroom_id,tipo,updated_at,updated_by'));
        $this->assertKeys(CajonResource::class, Cajon::factory()->create(['location_id' => $location->id]), explode(',', 'barcode,codigo,created_at,created_by,descripcion,id,location_id,updated_at,updated_by'));
        $this->assertKeys(CareerResource::class, Career::factory()->create(), explode(',', 'code,created_at,created_by,id,level,level_label,name,updated_at,updated_by'));
    }

    public function test_budget_and_invoice_intake_resources(): void
    {
        $budget = AnnualBudget::factory()->create();
        $this->assertKeys(AnnualBudgetResource::class, $budget, explode(',', 'available_amount,career_id,consumed_amount,created_at,created_by,free_amount,id,initial_amount,reserved_amount,updated_at,updated_by,year'));

        $intake = InvoiceIntake::factory()->create(['annual_budget_id' => $budget->id]);
        $this->assertKeys(InvoiceIntakeResource::class, $intake, explode(',', 'annual_budget_id,created_at,detected_career_id,document_number,has_file,id,linked,needs_budget,purchase_id,raw_ocr_payload,received_at,status,total_deducted,updated_at,user_id'));

        $this->assertKeys(BudgetSummaryResource::class, $budget, explode(',', 'available_amount,career_id,consumed_amount,execution_percent,free_amount,id,initial_amount,reserved_amount,year'));
        $movement = BudgetMovement::create(['annual_budget_id' => $budget->id, 'type' => BudgetMovement::TYPE_INITIAL, 'amount' => 1, 'user_id' => User::factory()->create()->id]);
        $this->assertKeys(BudgetMovementResource::class, $movement, explode(',', 'amount,annual_budget_id,created_at,id,invoice_intake_id,notes,purchase_id,type,user_id'));

        $item = InvoiceIntakeItem::create(['invoice_intake_id' => $intake->id, 'product_id' => Product::factory()->create()->id, 'quantity' => 1]);
        $this->assertKeys(InvoiceIntakeItemResource::class, $item, explode(',', 'detected_name,id,invoice_intake_id,product_id,quantity,subtotal,unit_price'));
    }

    public function test_product_and_stock_alert_resources(): void
    {
        $product = Product::factory()->create();

        $this->assertKeys(ProductResource::class, $product, explode(',', 'area,barcode,cajon_id,career_id,category_id,created_at,created_by,description,estado,id,is_active,is_critical_stock,is_warning_stock,location_id,maintenance_frequency_months,maintenance_priority,name,photo_url,quantity,quantity_in_repair,quantity_written_off,reference_price,requires_calibration,stock_minimo,supplier_id,type,updated_at,updated_by'));

        $alert = StockAlert::create(['product_id' => $product->id, 'alert_type' => 'warning', 'current_stock' => 1, 'stock_minimo' => 1, 'message' => 'm', 'is_resolved' => false]);
        $this->assertKeys(StockAlertResource::class, $alert, explode(',', 'alert_type,created_at,current_stock,id,is_resolved,message,product_barcode,product_id,product_name,stock_minimo'));
    }

    public function test_loan_resources(): void
    {
        $product = Product::factory()->create();
        $loan = Loan::factory()->create();
        $item = LoanItem::create(['loan_id' => $loan->id, 'product_id' => $product->id, 'name' => 'x', 'quantity' => 1]);

        $this->assertKeys(LoanResource::class, $loan, explode(',', 'allowed_transitions,approved_at,approved_by,borrower_document,borrower_name,career_id,code,created_at,due_date,end_time,id,is_in_progress,is_overdue,is_pending,is_processed,is_rejected,is_returned,loan_date,location_id,notes,processed_at,processed_by,rejection_reason,requested_by,return_notes,returned_at,returned_by,room,start_time,status,status_label,storeroom_id,student_id,subject,time_block,type,updated_at'));
        $this->assertKeys(LoanItemResource::class, $item, explode(',', 'available_stock,cajon,delivered_quantity,id,is_returned,loan_id,location,name,product_id,quantity,return_condition,returned_at,returned_quantity'));
    }

    public function test_purchase_and_quotation_resources(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_DIRECTOR]);
        $supplier = Supplier::factory()->create();

        $purchase = Purchase::factory()->create(['created_by' => $user->id]);
        $purchaseItem = $purchase->items()->create(['name' => 'a', 'quantity' => 1, 'unit_price' => 1]);
        $this->assertKeys(PurchaseResource::class, $purchase, explode(',', 'allowed_transitions,annual_budget_id,arrival_document,arrival_document_url,code,created_at,created_by,expected_at,guide_number,id,invoice_number,min_quotations,notes,quotation_id,quotation_justification,quotation_reference,received_at,reserved_amount,status,supplier_id,total,updated_at'));
        $this->assertKeys(PurchaseItemResource::class, $purchaseItem, explode(',', 'id,name,product_id,purchase_id,quantity,unit_price'));

        $quotation = Quotation::factory()->create(['created_by' => $user->id]);
        $this->assertKeys(QuotationResource::class, $quotation, explode(',', 'allowed_transitions,career_id,code,converted_at,created_at,created_by,expires_at,id,meets_min_quotations,min_quotations,notes,purchase_id,responded_suppliers,status,title,updated_at'));
        $this->assertKeys(QuotationItemResource::class, $quotation->items()->create(['name' => 'a', 'quantity' => 1]), explode(',', 'id,name,product_id,quantity,quotation_id'));
        $this->assertKeys(QuotationSupplierResource::class, $quotation->suppliers()->create(['supplier_id' => $supplier->id]), explode(',', 'contacted_at,email_error,email_sent_at,has_backup_file,id,notes,offer_total,quotation_id,quotation_number,responded_at,status,supplier_id'));
    }

    public function test_equipment_unit_resource(): void
    {
        $this->assertKeys(EquipmentUnitResource::class, EquipmentUnit::factory()->create(), explode(',', 'cajon_id,code,created_at,created_by,id,last_maintenance_at,next_maintenance_at,product_id,serial_number,status,updated_at,updated_by'));
    }

    public function test_incident_report_resource(): void
    {
        $report = IncidentReport::factory()->create(['product_id' => Product::factory()->create()->id]);

        $this->assertKeys(IncidentReportResource::class, $report, explode(',', 'allowed_transitions,attachment,attachment_original_name,attachment_url,code,created_at,description,equipment_unit_id,has_evidence_photo,has_signature,id,is_legacy,location_id,pdf_url,problems,product_id,report_date,reported_by,request_type,severity,severity_label,signature_url,signed_at,status,subject,suggestion,teacher_name,title,updated_at'));
    }

    public function test_student_resource(): void
    {
        $this->assertKeys(StudentResource::class, Student::factory()->create(), explode(',', 'career_id,created_at,email,full_name,id,registered_at,rut,updated_at'));
    }

    public function test_maintenance_resources(): void
    {
        $record = MaintenanceRecord::factory()->create();
        $this->assertKeys(MaintenanceRecordResource::class, $record, explode(',', 'allowed_transitions,applies_recf,checklist_complete,created_at,created_by,equipment_unit_id,execution_mode,external_supplier_id,finished_at,id,incident_report_id,maintenance_type,pfg_approved,repair_cost,requires_additional_maintenance,responsible_id,result,rev_a_approved,rev_b_approved,rev_c_approved,spare_parts_used,started_at,status,technical_notes,updated_at,updated_by'));

        $document = MaintenanceDocument::create([
            'equipment_unit_id' => $record->equipment_unit_id,
            'uploaded_by' => $record->responsible_id,
            'document_type' => MaintenanceDocument::TYPE_MANUAL,
            'title' => 'Manual',
            'file_path' => 'maintenance-documents/x.pdf',
        ]);
        $this->assertKeys(MaintenanceDocumentResource::class, $document, explode(',', 'created_at,document_type,equipment_unit_id,file_url,id,is_expired,maintenance_record_id,original_name,size_bytes,title,updated_at,uploaded_by,valid_until'));
    }

    public function test_chart_resources(): void
    {
        $template = ChartTemplate::factory()->create();
        $this->assertKeys(ChartTemplateResource::class, $template, explode(',', 'chart_type,code,description,entity,group_by,id,metric,name,period,visualization_type'));
        $this->assertKeys(UserChartResource::class, UserChart::factory()->create(['chart_template_id' => $template->id]), explode(',', 'chart_template_id,created_at,custom_title,date_from,date_to,entity,group_by,id,is_active,metric,metric_input,period,position,type,updated_at,user_id'));
    }

    public function test_audit_log_resource(): void
    {
        $log = AuditLog::create(['action' => 'user.created', 'auditable_type' => 'user', 'auditable_id' => 1]);
        $this->assertKeys(AuditLogResource::class, $log->fresh(), explode(',', 'action,auditable_id,auditable_type,created_at,id,ip_address,new_values,old_values,user_agent,user_id'));
    }
}
