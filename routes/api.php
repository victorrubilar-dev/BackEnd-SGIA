<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CajonController;
use App\Http\Controllers\Api\EquipmentController;
use App\Http\Controllers\Api\EquipmentReportController;
use App\Http\Controllers\Api\InvoiceScanController;
use App\Http\Controllers\Api\LoanController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\QuotationController;
use App\Http\Controllers\Api\StockAlertController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/user', [AuthController::class, 'me']);
    Route::put('/password', [AuthController::class, 'changePassword']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/logout-all', [AuthController::class, 'logoutAll']);
    Route::get('/tokens', [AuthController::class, 'tokens']);
    Route::delete('/tokens/{tokenId}', [AuthController::class, 'revokeToken']);
    Route::post('/tokens/revoke-others', [AuthController::class, 'revokeOtherTokens']);
    Route::post('/tokens/revoke-all', [AuthController::class, 'logoutAll']);

    // Administración de usuarios (solo AD-01)
    Route::middleware('role:AD-01')->group(function () {
        Route::patch('/users/{user}/status', [UserController::class, 'updateStatus']);
        Route::apiResource('users', UserController::class);
    });

    // REQ-03: Escaneo de facturas (AD-01 y DIR-01)
    Route::middleware('role:AD-01,DIR-01')->group(function () {
        Route::post('/invoices/scan', [InvoiceScanController::class, 'scan']);
        Route::post('/products/scan-invoice', [InvoiceScanController::class, 'scan']);
    });

    // REQ-06: Alertas de stock crítico
    Route::get('/alerts/critical-stock', [StockAlertController::class, 'index']);
    Route::patch('/alerts/{stockAlert}/resolve', [StockAlertController::class, 'resolve']);

    // REQ-08: Compras y órdenes de compra (FU-03)
    Route::middleware('role:AD-01,DIR-01,PAN-01')->group(function () {
        Route::get('/purchases', [PurchaseController::class, 'index']);
        Route::get('/purchases/{purchase}', [PurchaseController::class, 'show']);
        Route::patch('/purchases/{purchase}/status', [PurchaseController::class, 'updateStatus']);
        Route::post('/purchases/{purchase}/arrival-scan', [PurchaseController::class, 'arrivalScan']);
    });
    Route::middleware('role:AD-01,DIR-01')->group(function () {
        Route::post('/purchases', [PurchaseController::class, 'store']);
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

    // REQ-07: Cotizaciones automáticas (FU-03)
    Route::middleware('role:AD-01,DIR-01')->group(function () {
        Route::get('/quotations', [QuotationController::class, 'index']);
        Route::post('/quotations', [QuotationController::class, 'store']);
        Route::get('/quotations/{quotation}', [QuotationController::class, 'show']);
        Route::patch('/quotations/{quotation}/status', [QuotationController::class, 'updateStatus']);
        Route::patch('/quotations/{quotation}/responses', [QuotationController::class, 'storeResponse']);
    });

    // REQ-09: Solicitud de préstamo remoto (FU-04, docente)
    // Las rutas estáticas van antes de cualquier GET /loans/{loan} (REQ-11).
    Route::middleware('role:PRO-01')->group(function () {
        Route::post('/loans/requests', [LoanController::class, 'store']);
        Route::get('/loans/requests', [LoanController::class, 'myRequests']);
        Route::get('/loans/my-requests', [LoanController::class, 'myRequests']);
    });

    // REQ-10: Procesamiento de préstamos presenciales/remotos (FU-04, pañol)
    Route::middleware('role:PAN-01,AD-01')->group(function () {
        Route::get('/loans/pending', [LoanController::class, 'pending']);
        Route::post('/loans/checkout', [LoanController::class, 'storeCheckout']);
        Route::post('/loans/{loan}/approve', [LoanController::class, 'approve']);
        Route::post('/loans/{loan}/reject', [LoanController::class, 'reject']);
        Route::post('/loans/{loan}/deliver', [LoanController::class, 'deliver']);
    });

    // REQ-11: Historial y listado de préstamos (FU-04)
    // Va después de las rutas estáticas (/loans/requests, /loans/pending,
    // /loans/checkout) para que no las capture {loan}.
    Route::middleware('role:PAN-01,DIR-01,AD-01')->group(function () {
        Route::get('/loans', [LoanController::class, 'index']);
        Route::get('/loans/{loan}', [LoanController::class, 'show']);
    });

    // REQ-05: Ubicaciones (Salas, Pañoles) y Cajones (FU-02)
    Route::get('/locations', [LocationController::class, 'index']);
    Route::get('/locations/{location}', [LocationController::class, 'show']);
    Route::middleware('role:AD-01,DIR-01,PAN-01')->group(function () {
        Route::post('/locations', [LocationController::class, 'store']);
        Route::put('/locations/{location}', [LocationController::class, 'update']);
        Route::patch('/locations/{location}', [LocationController::class, 'update']);
        Route::delete('/locations/{location}', [LocationController::class, 'destroy']);
    });

    Route::get('/cajones', [CajonController::class, 'index']);
    Route::get('/cajones/{cajon}', [CajonController::class, 'show']);
    Route::middleware('role:AD-01,DIR-01,PAN-01')->group(function () {
        Route::post('/cajones', [CajonController::class, 'store']);
        Route::put('/cajones/{cajon}', [CajonController::class, 'update']);
        Route::patch('/cajones/{cajon}', [CajonController::class, 'update']);
        Route::delete('/cajones/{cajon}', [CajonController::class, 'destroy']);
    });

    // REQ-12: Fichas técnicas de equipos (FU-05)
    // /equipment/{id}/technical-sheet: PDF con datos, proveedor,
    // ubicación física e historial de novedades (todos los autenticados).
    Route::get('/equipment/{product}/technical-sheet', [EquipmentController::class, 'technicalSheet']);
    // /equipment/{id}/reports: informes de novedades asociados al equipo,
    // en JSON (paginado) o descargables en PDF con ?format=pdf.
    Route::get('/equipment/{product}/reports', [EquipmentReportController::class, 'index']);

    // REQ-13: Solicitud de reposición mediante informe de novedades
    // (FU-05, PRO-01/PAN-01): formulario + adjunto opcional, asociado
    // al equipo para su hoja de vida.
    Route::middleware('role:PRO-01,PAN-01')->group(function () {
        Route::post('/equipment/{product}/reports', [EquipmentReportController::class, 'store']);
    });

    // REQ-03, REQ-04, REQ-05: Productos
    Route::get('/products/{product}/location', [ProductController::class, 'location']);
    Route::patch('/products/{product}/location', [ProductController::class, 'updateLocation']);
    Route::get('/products/{product}/barcode', [ProductController::class, 'barcode']);
    Route::patch('/products/{product}/status', [ProductController::class, 'updateStatus']);
    Route::apiResource('products', ProductController::class);
});
