<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\InventoryAdjustmentController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\InventoryMovementController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('api.v1.auth.login');

    Route::middleware('auth:sanctum')->prefix('auth')->group(function (): void {
        Route::get('/me', [AuthController::class, 'me'])->name('api.v1.auth.me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('api.v1.auth.logout');
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/inventory', [InventoryController::class, 'index'])->name('api.v1.inventory.index');
        Route::post('/inventory/movements', [InventoryMovementController::class, 'store'])
            ->name('api.v1.inventory.movements.store');
        Route::post('/inventory/adjustments', [InventoryAdjustmentController::class, 'store'])
            ->name('api.v1.inventory.adjustments.store');
        Route::post('/inventory/adjustments/{adjustment}/review', [InventoryAdjustmentController::class, 'review'])
            ->name('api.v1.inventory.adjustments.review');
    });
});
