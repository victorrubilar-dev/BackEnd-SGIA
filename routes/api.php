<?php

use App\Http\Controllers\Api\AuthController;
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
});
