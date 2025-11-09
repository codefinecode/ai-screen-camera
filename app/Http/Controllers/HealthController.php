<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class HealthController extends Controller
{
    private const TIMEOUT_SECONDS = 5;

    public function health(): JsonResponse
    {
        $startTime = microtime(true);
        $checks = [];
        $overallStatus = 'ok';

        // Check Redis
        $redisCheck = $this->checkRedis();
        $checks['redis'] = $redisCheck;
        if ($redisCheck['status'] !== 'ok') {
            $overallStatus = 'degraded';
        }

        // Check Queue
        $queueCheck = $this->checkQueue();
        $checks['queue'] = $queueCheck;
        if ($queueCheck['status'] !== 'ok') {
            $overallStatus = 'degraded';
        }

        // Check Disk
        $diskCheck = $this->checkDisk();
        $checks['disk'] = $diskCheck;
        if ($diskCheck['status'] !== 'ok') {
            $overallStatus = 'degraded';
        }

        // Check AWS (optional)
        if (config('services.aws.ingest_url')) {
            $awsCheck = $this->checkAws();
            $checks['aws'] = $awsCheck;
            if ($awsCheck['status'] !== 'ok') {
                // AWS is optional, so don't mark as degraded
                $checks['aws']['optional'] = true;
            }
        }

        $duration = round((microtime(true) - $startTime) * 1000, 2);

        $response = [
            'status' => $overallStatus,
            'timestamp' => now()->toIso8601String(),
            'duration_ms' => $duration,
            'checks' => $checks
        ];

        $statusCode = $overallStatus === 'ok' ? 200 : 503;

        return response()->json($response, $statusCode);
    }

    /**
     * Check Redis connectivity
     */
    private function checkRedis(): array
    {
        try {
            $start = microtime(true);
            Redis::ping();
            $duration = round((microtime(true) - $start) * 1000, 2);

            return [
                'status' => 'ok',
                'duration_ms' => $duration
            ];
        } catch (\Exception $e) {
            Log::error('Health check: Redis failed', [
                'error' => $e->getMessage()
            ]);

            return [
                'status' => 'error',
                'error' => 'Redis connection failed'
            ];
        }
    }

    /**
     * Check queue status
     */
    private function checkQueue(): array
    {
        try {
            $start = microtime(true);
            $queueSize = DB::table('jobs')->count();
            $duration = round((microtime(true) - $start) * 1000, 2);

            $status = 'ok';
            if ($queueSize > 1000) {
                $status = 'warning';
            }

            return [
                'status' => $status,
                'size' => $queueSize,
                'duration_ms' => $duration
            ];
        } catch (\Exception $e) {
            Log::error('Health check: Queue failed', [
                'error' => $e->getMessage()
            ]);

            return [
                'status' => 'error',
                'error' => 'Queue check failed'
            ];
        }
    }

    /**
     * Check disk space
     */
    private function checkDisk(): array
    {
        try {
            $start = microtime(true);
            $path = storage_path();
            $freeSpace = disk_free_space($path);
            $totalSpace = disk_total_space($path);
            $duration = round((microtime(true) - $start) * 1000, 2);

            if ($freeSpace === false || $totalSpace === false) {
                return [
                    'status' => 'error',
                    'error' => 'Unable to check disk space'
                ];
            }

            $usedPercent = round((($totalSpace - $freeSpace) / $totalSpace) * 100, 2);
            $status = 'ok';

            if ($usedPercent > 90) {
                $status = 'warning';
            }

            return [
                'status' => $status,
                'used_percent' => $usedPercent,
                'free_mb' => round($freeSpace / 1024 / 1024, 2),
                'duration_ms' => $duration
            ];
        } catch (\Exception $e) {
            Log::error('Health check: Disk failed', [
                'error' => $e->getMessage()
            ]);

            return [
                'status' => 'error',
                'error' => 'Disk check failed'
            ];
        }
    }

    /**
     * Check AWS connectivity (optional)
     */
    private function checkAws(): array
    {
        try {
            $url = config('services.aws.ingest_url');
            if (!$url) {
                return [
                    'status' => 'skipped',
                    'reason' => 'AWS URL not configured'
                ];
            }

            $start = microtime(true);
            $response = Http::timeout(self::TIMEOUT_SECONDS)->head($url);
            $duration = round((microtime(true) - $start) * 1000, 2);

            $status = $response->successful() ? 'ok' : 'error';

            return [
                'status' => $status,
                'http_code' => $response->status(),
                'duration_ms' => $duration
            ];
        } catch (\Exception $e) {
            Log::warning('Health check: AWS failed', [
                'error' => $e->getMessage()
            ]);

            return [
                'status' => 'error',
                'error' => 'AWS connectivity check failed'
            ];
        }
    }
}
