<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BatchController;
use App\Http\Controllers\Api\V1\InventoryAdjustmentController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\InventoryMovementController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\TrashController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Middleware\EnsureAccountAccess;
use App\Http\Middleware\EnsureLoginPortal;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('api.v1.auth.login');
    Route::post('/auth/admin/login', [AuthController::class, 'login'])
        ->defaults('portal', 'admin')->middleware('throttle:login')->name('api.v1.auth.admin.login');

    Route::middleware(['auth:sanctum', EnsureAccountAccess::class.':password'])->prefix('auth')->group(function (): void {
        Route::get('/me', [AuthController::class, 'me'])->name('api.v1.auth.me');
        Route::get('/admin/me', [AuthController::class, 'me'])->middleware(EnsureLoginPortal::class.':admin')->name('api.v1.auth.admin.me');
        Route::get('/staff/me', [AuthController::class, 'me'])->middleware(EnsureLoginPortal::class.':staff')->name('api.v1.auth.staff.me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('api.v1.auth.logout');
        Route::post('/password', [AuthController::class, 'changePassword'])->middleware('throttle:6,1')->name('api.v1.auth.password');
    });

    Route::middleware(['auth:sanctum', EnsureAccountAccess::class])->group(function (): void {
        Route::get('/admin/overview', [InventoryController::class, 'index'])->middleware(EnsureLoginPortal::class.':admin')->name('api.v1.admin.overview');
        Route::get('/staff/overview', [InventoryController::class, 'index'])->middleware(EnsureLoginPortal::class.':staff')->name('api.v1.staff.overview');
        Route::get('/inventory', [InventoryController::class, 'index'])->name('api.v1.inventory.index');
        Route::get('/trash', [TrashController::class, 'index'])->name('api.v1.trash.index');
        Route::post('/trash/{type}/{id}/restore', [TrashController::class, 'restore'])->whereNumber('id')->name('api.v1.trash.restore');
        Route::get('/notifications', [NotificationController::class, 'index'])->name('api.v1.notifications.index');
        Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('api.v1.notifications.read-all');
        Route::post('/notifications/{id}/read', [NotificationController::class, 'read'])->whereUuid('id')->name('api.v1.notifications.read');
        Route::get('/inventory/history/{section}', [InventoryController::class, 'history'])->name('api.v1.inventory.history');
        Route::post('/inventory/movements', [InventoryMovementController::class, 'store'])
            ->name('api.v1.inventory.movements.store');
        Route::post('/inventory/adjustments', [InventoryAdjustmentController::class, 'store'])
            ->name('api.v1.inventory.adjustments.store');
        Route::post('/inventory/adjustments/{adjustment}/review', [InventoryAdjustmentController::class, 'review'])
            ->name('api.v1.inventory.adjustments.review');
        Route::apiResource('products', ProductController::class)->except(['show']);
        Route::apiResource('batches', BatchController::class)->except(['show']);
        Route::apiResource('users', UserController::class)->except(['show']);
    });
});
