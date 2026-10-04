<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\InvoiceScanController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\StockAlertController;
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

    // REQ-03, REQ-04, REQ-05: Productos
    Route::get('/products/{product}/location', [ProductController::class, 'location']);
    Route::patch('/products/{product}/location', [ProductController::class, 'updateLocation']);
    Route::get('/products/{product}/barcode', [ProductController::class, 'barcode']);
    Route::patch('/products/{product}/status', [ProductController::class, 'updateStatus']);
    Route::apiResource('products', ProductController::class);
});
