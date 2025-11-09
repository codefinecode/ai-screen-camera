<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\FramesController;

// TZ compatibility: endpoints without /api prefix
Route::get('/health', [HealthController::class, 'health']);
Route::post('/frames', [FramesController::class, 'store'])
    ->middleware(['throttle:frames', \App\Http\Middleware\ValidatePayloadSize::class]);
Route::post('/v1/frames', [FramesController::class, 'store'])
    ->middleware(['throttle:frames', \App\Http\Middleware\ValidatePayloadSize::class]);
