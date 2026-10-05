<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\InvoiceScanController;
use App\Http\Controllers\Api\LoanController;
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

    // REQ-03, REQ-04, REQ-05: Productos
    Route::get('/products/{product}/location', [ProductController::class, 'location']);
    Route::patch('/products/{product}/location', [ProductController::class, 'updateLocation']);
    Route::get('/products/{product}/barcode', [ProductController::class, 'barcode']);
    Route::patch('/products/{product}/status', [ProductController::class, 'updateStatus']);
    Route::apiResource('products', ProductController::class);
});
