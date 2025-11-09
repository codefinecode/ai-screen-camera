<?php

namespace App\Providers;

use App\Contracts\AwsFramesReaderInterface;
use App\Contracts\PlayerStateRepositoryInterface;
use App\Contracts\TriggerEngineInterface;
use App\Services\AwsFramesReader;
use App\Services\PlayerStateRepository;
use App\Services\TriggerEngine;
use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Request;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PlayerStateRepositoryInterface::class, PlayerStateRepository::class);
        $this->app->bind(AwsFramesReaderInterface::class, AwsFramesReader::class);
        $this->app->bind(TriggerEngineInterface::class, TriggerEngine::class);
    }

    public function boot(): void
    {
        $this->configureRateLimiting();
    }
    
    /**
     * Configure rate limiting for API endpoints
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('frames', function (Request $request) {
            $perMinute = (int) env('API_FRAMES_RATE_LIMIT', 60);
            
            return Limit::perMinute($perMinute)
                ->by($request->ip())
                ->response(function (Request $request, array $headers) {
                    \Illuminate\Support\Facades\Log::warning('Rate limit exceeded', [
                        'ip' => $request->ip(),
                        'path' => $request->path(),
                        'method' => $request->method()
                    ]);
                    
                    return response()->json([
                        'error' => 'RATE_LIMIT_EXCEEDED',
                        'message' => 'Too many requests. Please try again later.'
                    ], 429, $headers);
                });
        });
    }
}
