<?php

use App\Http\Controllers\Api\AnnualBudgetController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BudgetReportController;
use App\Http\Controllers\Api\CajonController;
use App\Http\Controllers\Api\CareerController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\DeviceTokenController;
use App\Http\Controllers\Api\EquipmentController;
use App\Http\Controllers\Api\EquipmentReportController;
use App\Http\Controllers\Api\EquipmentUnitController;
use App\Http\Controllers\Api\IncidentReportController;
use App\Http\Controllers\Api\InvoiceIntakeController;
use App\Http\Controllers\Api\InvoiceScanController;
use App\Http\Controllers\Api\LoanController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\MaintenanceDocumentController;
use App\Http\Controllers\Api\MaintenanceRecordController;
use App\Http\Controllers\Api\MetricsController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\PurchaseRequestController;
use App\Http\Controllers\Api\QuotationController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\StockAlertController;
use App\Http\Controllers\Api\StoreroomController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\UserChartController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/password', [AuthController::class, 'changePassword'])->middleware('throttle:sensitive');
    Route::post('/devices', [DeviceTokenController::class, 'store']);
    Route::delete('/devices/{id}', [DeviceTokenController::class, 'destroy']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/logout-all', [AuthController::class, 'logoutAll']);
    Route::get('/tokens', [AuthController::class, 'tokens']);
    Route::delete('/tokens/{tokenId}', [AuthController::class, 'revokeToken']);
    Route::post('/tokens/revoke-others', [AuthController::class, 'revokeOtherTokens']);

    // Administración de usuarios (solo AD-01)
    Route::middleware('role:AD-01')->group(function () {
        Route::patch('/users/{user}/status', [UserController::class, 'updateStatus']);
        Route::apiResource('users', UserController::class);
        // Catálogo fijo de roles para el formulario de usuarios.
        Route::get('/roles', [RoleController::class, 'index']);
        // REQ-NF-01: registro de eventos críticos (solo lectura).
        Route::get('/audit-logs', [AuditLogController::class, 'index']);
    });

    // Pañoles: lectura para todos, escritura solo AD-01
    Route::get('/storerooms', [StoreroomController::class, 'index']);
    Route::get('/storerooms/{storeroom}', [StoreroomController::class, 'show']);
    Route::middleware('role:AD-01')->group(function () {
        Route::post('/storerooms', [StoreroomController::class, 'store']);
        Route::patch('/storerooms/{storeroom}', [StoreroomController::class, 'update']);
        Route::delete('/storerooms/{storeroom}', [StoreroomController::class, 'destroy']);
    });

    // REQ-14: Catálogo de carreras y su nivel (dashboards CAREERS por level)
    // PRO-01 lee las carreras (solo lectura) para el selector de la solicitud de préstamo.
    Route::middleware('role:AD-01,DIR-01,PAN-01,PRO-01')->group(function () {
        Route::get('/careers', [CareerController::class, 'index']);
        Route::get('/careers/{career}', [CareerController::class, 'show']);
    });
    Route::middleware('role:AD-01,DIR-01,PAN-01')->group(function () {
        // Catálogo para el alta de productos (`category_id`).
        Route::get('/categories', [CategoryController::class, 'index']);
    });
    Route::middleware('role:AD-01')->group(function () {
        Route::post('/careers', [CareerController::class, 'store']);
        Route::patch('/careers/{career}', [CareerController::class, 'update']);
        Route::delete('/careers/{career}', [CareerController::class, 'destroy']);
    });

    // REQ-03: Escaneo de facturas (AD-01 y DIR-01)
    Route::middleware(['role:AD-01,DIR-01', 'throttle:sensitive'])->group(function () {
        Route::post('/invoices/scan', [InvoiceScanController::class, 'scan']);
        Route::post('/products/scan-invoice', [InvoiceScanController::class, 'scan']);
    });

    // REQ-16 / REQ-24: Presupuesto anual por carrera (AD-01 y DIR-01, director del área: todas las carreras) y reportes
    Route::middleware('role:AD-01,DIR-01')->group(function () {
        Route::get('/budgets', [AnnualBudgetController::class, 'index']);
        Route::get('/budgets/summary', [BudgetReportController::class, 'summary']);
        Route::get('/budgets/{annualBudget}', [AnnualBudgetController::class, 'show']);
        Route::get('/budgets/{annualBudget}/movements', [BudgetReportController::class, 'movements']);
        Route::get('/budgets/{annualBudget}/spending', [BudgetReportController::class, 'spending']);
        Route::get('/budgets/{annualBudget}/monthly', [BudgetReportController::class, 'monthly']);
        Route::post('/budgets', [AnnualBudgetController::class, 'store']);
        Route::patch('/budgets/{annualBudget}', [AnnualBudgetController::class, 'update']);
        Route::delete('/budgets/{annualBudget}', [AnnualBudgetController::class, 'destroy']);
    });

    // REQ-17: Ingreso de facturas con descuento de presupuesto (AD-01 y DIR-01)
    Route::middleware('role:AD-01,DIR-01')->group(function () {
        Route::post('/invoice-intakes', [InvoiceIntakeController::class, 'store'])->middleware('throttle:sensitive');
        Route::get('/invoice-intakes', [InvoiceIntakeController::class, 'index']);
        Route::get('/invoice-intakes/{invoiceIntake}', [InvoiceIntakeController::class, 'show']);
        Route::get('/invoice-intakes/{invoiceIntake}/file', [InvoiceIntakeController::class, 'file']);
        Route::post('/invoice-intakes/{invoiceIntake}/confirm', [InvoiceIntakeController::class, 'confirm']);
        Route::post('/invoice-intakes/{invoiceIntake}/cancel', [InvoiceIntakeController::class, 'cancel']);
    });

    // REQ-06: Alertas de stock crítico
    Route::middleware('role:AD-01,DIR-01,PAN-01')->get('/alerts/critical-stock', [StockAlertController::class, 'index']);
    Route::middleware('role:AD-01,DIR-01,PAN-01')->patch('/alerts/{stockAlert}/resolve', [StockAlertController::class, 'resolve']);

    // REQ-08: Compras y órdenes de compra (FU-03)
    Route::middleware('role:AD-01,DIR-01,PAN-01')->group(function () {
        Route::get('/purchases', [PurchaseController::class, 'index']);
        Route::get('/purchases/{purchase}', [PurchaseController::class, 'show']);
        Route::get('/purchases/{purchase}/trace', [PurchaseController::class, 'trace']);
        Route::patch('/purchases/{purchase}/status', [PurchaseController::class, 'updateStatus']);
        Route::post('/purchases/{purchase}/arrival-scan', [PurchaseController::class, 'arrivalScan'])->middleware('throttle:sensitive');
        Route::post('/purchases/{purchase}/cancel', [PurchaseController::class, 'cancel']);
        Route::get('/purchases/{purchase}/arrival-document', [PurchaseController::class, 'arrivalDocument']);
    });
    // Las OC se crean solo desde la solicitud de compra (`POST /purchase-requests/{id}/order`); `POST /purchases` se retiró.

    // Nuevo flujo de compras: solicitud de compra (NUEVO-FLUJO-COMPRAS.md). Lectura AD-01, DIR-01, PAN-01; escritura solo DIR-01.
    // `/purchase-requests/areas` va antes de `{purchaseRequest}`.
    Route::middleware('role:AD-01,DIR-01,PAN-01')->group(function () {
        Route::get('/purchase-requests', [PurchaseRequestController::class, 'index']);
        Route::get('/purchase-requests/areas', [PurchaseRequestController::class, 'areas']);
        Route::get('/purchase-requests/{purchaseRequest}', [PurchaseRequestController::class, 'show']);
        Route::get('/purchase-requests/{purchaseRequest}/quotations/{quotation}/file', [PurchaseRequestController::class, 'quotationFile'])->scopeBindings();
        Route::get('/purchase-requests/{purchaseRequest}/order/file', [PurchaseRequestController::class, 'orderFile']);
    });
    Route::middleware('role:DIR-01')->group(function () {
        Route::post('/purchase-requests', [PurchaseRequestController::class, 'store']);
        Route::patch('/purchase-requests/{purchaseRequest}', [PurchaseRequestController::class, 'update']);
        Route::post('/purchase-requests/{purchaseRequest}/approve', [PurchaseRequestController::class, 'approve']);
        Route::post('/purchase-requests/{purchaseRequest}/send', [PurchaseRequestController::class, 'send'])->middleware('throttle:sensitive');
        Route::post('/purchase-requests/{purchaseRequest}/resend', [PurchaseRequestController::class, 'resend'])->middleware('throttle:sensitive');
        Route::post('/purchase-requests/{purchaseRequest}/quotations', [PurchaseRequestController::class, 'storeQuotation']);
        Route::delete('/purchase-requests/{purchaseRequest}/quotations/{quotation}', [PurchaseRequestController::class, 'destroyQuotation'])->scopeBindings();
        Route::post('/purchase-requests/{purchaseRequest}/select-quotation', [PurchaseRequestController::class, 'selectQuotation']);
        Route::post('/purchase-requests/{purchaseRequest}/order', [PurchaseRequestController::class, 'storeOrder']);
        Route::post('/purchase-requests/{purchaseRequest}/cancel', [PurchaseRequestController::class, 'cancel']);
    });

    // REQ-07: Proveedores (FU-03)
    Route::middleware('role:AD-01,DIR-01,PAN-01')->group(function () {
        Route::get('/suppliers', [SupplierController::class, 'index']);
        Route::get('/suppliers/{supplier}', [SupplierController::class, 'show']);
    });
    Route::middleware('role:AD-01,DIR-01')->group(function () {
        Route::post('/suppliers', [SupplierController::class, 'store']);
        Route::put('/suppliers/{supplier}', [SupplierController::class, 'update']);
        Route::patch('/suppliers/{supplier}', [SupplierController::class, 'update']);
        Route::patch('/suppliers/{supplier}/status', [SupplierController::class, 'updateStatus']);
    });
    Route::middleware('role:AD-01')->group(function () {
        Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy']);
    });

    // REQ-07: Cotizaciones del flujo antiguo (FU-03), solo lectura del historial. Las escrituras se retiraron
    // (Fase 7 del nuevo flujo de compras): las cotizaciones nuevas se suben en la solicitud de compra.
    Route::middleware('role:AD-01,DIR-01')->group(function () {
        Route::get('/quotations', [QuotationController::class, 'index']);
        Route::get('/quotations/{quotation}', [QuotationController::class, 'show']);
    });

    // REQ-09: Solicitud de préstamo remoto (FU-04, docente)
    // Las rutas estáticas van antes de cualquier GET /loans/{loan} (REQ-11).
    Route::middleware('role:PRO-01')->group(function () {
        Route::post('/loans/requests', [LoanController::class, 'store']);
        Route::get('/loans/requests', [LoanController::class, 'myRequests']);
        Route::get('/loans/my-requests', [LoanController::class, 'myRequests']);
        Route::delete('/loans/requests/{loan}', [LoanController::class, 'cancel']);
    });

    // REQ-10: Procesamiento de préstamos presenciales/remotos (FU-04, pañol)
    Route::middleware('role:PAN-01,AD-01')->group(function () {
        Route::get('/loans/pending', [LoanController::class, 'pending']);
        Route::post('/loans/checkout', [LoanController::class, 'storeCheckout']);
        Route::post('/loans/checkin', [LoanController::class, 'checkin']);
        Route::get('/loans/{loan}/review', [LoanController::class, 'review']);
        Route::post('/loans/{loan}/approve', [LoanController::class, 'approve']);
        Route::post('/loans/{loan}/reject', [LoanController::class, 'reject']);
        Route::post('/loans/{loan}/deliver', [LoanController::class, 'deliver']);
    });

    // REQ-11: Historial y listado de préstamos (FU-04)
    // Va después de las rutas estáticas (/loans/requests, /loans/pending,
    // /loans/checkout) para que no las capture {loan}.
    Route::middleware('role:PAN-01,DIR-01,AD-01')->group(function () {
        Route::get('/loans/export', [LoanController::class, 'export']);
        Route::get('/loans', [LoanController::class, 'index']);
        Route::get('/loans/{loan}', [LoanController::class, 'show']);
    });

    // REQ-20: Registro de estudiantes (FU-04, PAN-01 y AD-01; borrado solo AD-01)
    Route::middleware('role:PAN-01,AD-01')->group(function () {
        Route::get('/students', [StudentController::class, 'index']);
        Route::get('/students/by-rut/{rut}', [StudentController::class, 'byRut']);
        Route::post('/students', [StudentController::class, 'store']);
        Route::get('/students/{student}', [StudentController::class, 'show']);
        Route::patch('/students/{student}', [StudentController::class, 'update']);
        Route::get('/students/{student}/loans', [StudentController::class, 'loans']);
    });
    Route::middleware('role:AD-01')->delete('/students/{student}', [StudentController::class, 'destroy']);

    // REQ-05: Ubicaciones (Salas, Pañoles) y Cajones (FU-02)
    Route::get('/locations', [LocationController::class, 'index']);
    Route::get('/locations/{location}', [LocationController::class, 'show']);
    Route::middleware('role:AD-01,DIR-01,PAN-01')->group(function () {
        Route::post('/locations', [LocationController::class, 'store']);
        Route::put('/locations/{location}', [LocationController::class, 'update']);
        Route::patch('/locations/{location}', [LocationController::class, 'update']);
        Route::delete('/locations/{location}', [LocationController::class, 'destroy']);
    });

    // Estática: antes de /cajones/{cajon}
    Route::post('/cajones/bulk-delete', [CajonController::class, 'bulkDestroy'])->middleware('role:AD-01,DIR-01');

    Route::get('/cajones', [CajonController::class, 'index']);
    Route::get('/cajones/{cajon}', [CajonController::class, 'show']);
    Route::middleware('role:AD-01,DIR-01,PAN-01')->group(function () {
        Route::post('/cajones', [CajonController::class, 'store']);
        Route::put('/cajones/{cajon}', [CajonController::class, 'update']);
        Route::patch('/cajones/{cajon}', [CajonController::class, 'update']);
        Route::delete('/cajones/{cajon}', [CajonController::class, 'destroy']);
    });

    // REQ-14: Dashboards (FU-06, DIR-01/AD-01): solo GraphQL
    Route::middleware('role:DIR-01,AD-01')->group(function () {
        Route::post('/metrics', MetricsController::class)->middleware('throttle:metrics');
    });

    // REQ-15: Dashboard personalizado (FU-06, DIR-01/AD-01; cada uno solo ve sus gráficos)
    Route::middleware('role:DIR-01,AD-01')->group(function () {
        Route::get('/chart-templates', [UserChartController::class, 'templates']);
        Route::get('/me/charts', [UserChartController::class, 'index']);
        Route::post('/me/charts', [UserChartController::class, 'store']);
        // Estática: antes de /me/charts/{userChart}
        Route::put('/me/charts/positions', [UserChartController::class, 'reorder']);
        Route::get('/me/charts/{userChart}', [UserChartController::class, 'show']);
        Route::patch('/me/charts/{userChart}', [UserChartController::class, 'update']);
        Route::delete('/me/charts/{userChart}', [UserChartController::class, 'destroy']);
    });

    // REQ-12: Fichas técnicas de equipos (FU-05)
    // /equipment/{id}/technical-sheet: PDF con datos, proveedor,
    // ubicación física e historial de novedades (todos los autenticados; quien no
    // atiende informes solo ve los que reportó).
    Route::get('/equipment/{product}/technical-sheet', [EquipmentController::class, 'technicalSheet']);
    // /equipment/{id}/reports: informes de novedades asociados al equipo,
    // en JSON (paginado) o descargables en PDF con ?format=pdf.
    Route::get('/equipment/{product}/reports', [EquipmentReportController::class, 'index']);
    // REQ-13: informe de novedades (formulario con firma, PRO-01 y PAN-01).
    Route::middleware(['role:PRO-01,PAN-01', 'throttle:sensitive'])->post('/incident-reports', [IncidentReportController::class, 'store']);
    // Estática: antes de /incident-reports/{incidentReport}
    Route::middleware('role:PRO-01')->get('/incident-reports/mine', [IncidentReportController::class, 'mine']);
    Route::get('/incident-reports', [EquipmentReportController::class, 'all']);
    Route::get('/incident-reports/{incidentReport}', [IncidentReportController::class, 'show']);
    Route::get('/incident-reports/{incidentReport}/pdf', [IncidentReportController::class, 'pdf']);
    Route::get('/incident-reports/{incidentReport}/signature', [IncidentReportController::class, 'signature']);
    Route::patch('/incident-reports/{incidentReport}/status', [EquipmentReportController::class, 'updateStatus']);
    // Los adjuntos viven en disco privado: se descargan autenticados.
    Route::get('/incident-reports/{incidentReport}/attachment', [EquipmentReportController::class, 'attachment']);
    Route::get('/incident-reports/{incidentReport}/evidence-photo', [EquipmentReportController::class, 'evidencePhoto']);

    // REQ-03, REQ-04, REQ-05: Productos
    Route::get('/products/{product}/location', [ProductController::class, 'location']);
    Route::patch('/products/{product}/location', [ProductController::class, 'updateLocation']);
    Route::get('/products/{product}/barcode', [ProductController::class, 'barcode']);
    Route::middleware('role:AD-01,DIR-01,PAN-01')->get('/products/{product}/label', [ProductController::class, 'label']);
    Route::patch('/products/{product}/status', [ProductController::class, 'updateStatus']);
    Route::patch('/products/{product}/condition', [ProductController::class, 'updateCondition']);
    Route::apiResource('products', ProductController::class);

    // Unidades de equipo (productos `equipo` y `maqueta`): lectura para todos,
    // escritura AD-01/DIR-01/PAN-01 y borrado AD-01/DIR-01 (EquipmentUnitPolicy).
    Route::get('/products/{product}/units', [EquipmentUnitController::class, 'indexForProduct']);
    Route::middleware('role:AD-01,DIR-01,PAN-01')->post('/products/{product}/units', [EquipmentUnitController::class, 'storeForProduct']);
    Route::get('/equipment-units', [EquipmentUnitController::class, 'index']);
    Route::get('/equipment-units/by-code/{code}', [EquipmentUnitController::class, 'byCode']);
    Route::get('/equipment-units/{equipmentUnit}', [EquipmentUnitController::class, 'show']);
    Route::get('/equipment-units/{equipmentUnit}/barcode', [EquipmentUnitController::class, 'barcode']);
    Route::middleware('role:AD-01,DIR-01,PAN-01')->get('/equipment-units/{equipmentUnit}/label', [EquipmentUnitController::class, 'label']);
    Route::get('/equipment-units/{equipmentUnit}/technical-sheet', [EquipmentUnitController::class, 'technicalSheet']);
    Route::get('/equipment-units/{equipmentUnit}/reports', [EquipmentReportController::class, 'unitIndex']);
    Route::middleware('role:AD-01,DIR-01,PAN-01')->group(function () {
        Route::patch('/equipment-units/{equipmentUnit}', [EquipmentUnitController::class, 'update']);
        Route::patch('/equipment-units/{equipmentUnit}/status', [EquipmentUnitController::class, 'updateStatus']);
    });
    Route::middleware('role:AD-01,DIR-01')->delete('/equipment-units/{equipmentUnit}', [EquipmentUnitController::class, 'destroy']);

    // REQ-18: Registro de mantenciones (FU-05, PAN-01 y AD-01; DIR-01 lectura)
    Route::middleware('role:AD-01,DIR-01,PAN-01')->group(function () {
        Route::get('/maintenance-records', [MaintenanceRecordController::class, 'index']);
        Route::get('/maintenance-records/summary', [MaintenanceRecordController::class, 'summary']);
        Route::get('/maintenance-records/{maintenanceRecord}', [MaintenanceRecordController::class, 'show']);
        Route::get('/equipment-units/{equipmentUnit}/maintenance-records', [MaintenanceRecordController::class, 'unitIndex']);
    });
    Route::middleware('role:AD-01,PAN-01')->group(function () {
        Route::post('/equipment-units/{equipmentUnit}/maintenance-records', [MaintenanceRecordController::class, 'store']);
        Route::patch('/maintenance-records/{maintenanceRecord}', [MaintenanceRecordController::class, 'update']);
        Route::post('/maintenance-records/{maintenanceRecord}/finish', [MaintenanceRecordController::class, 'finish']);
        Route::post('/maintenance-records/{maintenanceRecord}/cancel', [MaintenanceRecordController::class, 'cancel']);
    });

    // REQ-19: Documentos de mantenimiento (FU-05, PAN-01 y AD-01; DIR-01 lectura y descarga)
    Route::middleware('role:AD-01,DIR-01,PAN-01')->group(function () {
        Route::get('/equipment-units/{equipmentUnit}/maintenance-documents', [MaintenanceDocumentController::class, 'index']);
        Route::get('/maintenance-documents/{maintenanceDocument}/file', [MaintenanceDocumentController::class, 'file']);
    });
    Route::middleware('role:AD-01,PAN-01')->group(function () {
        Route::post('/equipment-units/{equipmentUnit}/maintenance-documents', [MaintenanceDocumentController::class, 'store'])->middleware('throttle:sensitive');
        Route::delete('/maintenance-documents/{maintenanceDocument}', [MaintenanceDocumentController::class, 'destroy']);
    });
});
