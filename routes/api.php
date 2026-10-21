<?php

use App\Http\Controllers\AttendantController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CleaningSessionController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ShoeController;
use App\Http\Controllers\TagController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'message' => 'Go Fresh Shoe Laundry API is connected.',
        'timestamp' => now()->toISOString(),
    ]);
});

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1');

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/dashboard', DashboardController::class);
    Route::get('/tags/available', [TagController::class, 'index']);

    Route::apiResource('clients', ClientController::class)->only(['index', 'store', 'show', 'update']);
    Route::post('/clients/{client}/shoes', [ShoeController::class, 'store']);
    Route::patch('/shoes/{shoe}', [ShoeController::class, 'update']);
    Route::apiResource('orders', OrderController::class)->only(['index', 'store', 'show']);
    Route::post('/orders/{order}/payments', [OrderController::class, 'addPayment']);
    Route::patch('/cleaning-sessions/{cleaningSession}/status', [CleaningSessionController::class, 'updateStatus']);
    Route::post('/cleaning-sessions/{cleaningSession}/notifications', [CleaningSessionController::class, 'resend']);

    Route::get('/reports', [ReportController::class, 'index'])->middleware('role:admin');
    Route::middleware('role:admin')->group(function () {
        Route::delete('/orders/{order}', [OrderController::class, 'destroy']);
        Route::get('/attendants', [AttendantController::class, 'index']);
        Route::post('/attendants', [AttendantController::class, 'store']);
        Route::patch('/attendants/{attendant}', [AttendantController::class, 'updateAccess']);
    });
});
