<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\FramesController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\PlayerController;
use App\Http\Controllers\DashboardFramesController;

Route::get('/health', [HealthController::class, 'health']);
Route::get('/metrics', [MetricsController::class, 'metrics']);

// Frame ingestion endpoints (multiple aliases for TZ compatibility)
Route::post('/v1/frames', [FramesController::class, 'store'])
    ->middleware(['throttle:frames', \App\Http\Middleware\ValidatePayloadSize::class]);
Route::post('/frames', [FramesController::class, 'store'])
    ->middleware(['throttle:frames', \App\Http\Middleware\ValidatePayloadSize::class]);

Route::post('/player/state', [PlayerController::class, 'state']);
Route::get('/player/stream', [PlayerController::class, 'stream']);

Route::get('/dashboards/frames', [DashboardFramesController::class, 'index']);
