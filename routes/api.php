<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CleaningSessionController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ShoeController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'message' => 'Align Sneaker Laundry API is connected.',
        'timestamp' => now()->toISOString(),
    ]);
});

Route::post('/login', [AuthController::class, 'login'])->middleware(['web', 'throttle:6,1']);

Route::middleware(['web', 'auth:sanctum'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/dashboard', DashboardController::class);

    Route::apiResource('clients', ClientController::class)->only(['index', 'store', 'show', 'update']);
    Route::post('/clients/{client}/shoes', [ShoeController::class, 'store']);
    Route::patch('/shoes/{shoe}', [ShoeController::class, 'update']);
    Route::apiResource('orders', OrderController::class)->only(['index', 'store', 'show']);
    Route::post('/orders/{order}/payments', [OrderController::class, 'addPayment']);
    Route::patch('/cleaning-sessions/{cleaningSession}/status', [CleaningSessionController::class, 'updateStatus']);
    Route::post('/cleaning-sessions/{cleaningSession}/notifications', [CleaningSessionController::class, 'resend']);

    Route::get('/reports', [ReportController::class, 'index'])->middleware('role:admin');
});
