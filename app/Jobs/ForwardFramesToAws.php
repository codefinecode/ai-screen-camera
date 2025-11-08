<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class ForwardFramesToAws implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const MAX_RETRIES = 10;
    private const BACKOFF_INTERVALS = [1, 2, 5, 10, 20, 30, 60, 120];

    public array $payload;

    public $tries = self::MAX_RETRIES;
    public $backoff = self::BACKOFF_INTERVALS;

    public function __construct(array $payload)
    {
        $this->payload = $payload;
    }

    public function handle(): void
    {
        try {
            $url = config('services.aws.ingest_url');
            if (!$url) {
                Log::info('AWS ingest URL not configured, skipping forward');
                return;
            }
            
            $token = config('services.aws.bearer_token');
            $headers = ['Accept' => 'application/json'];
            if ($token) {
                $headers['Authorization'] = 'Bearer '.$token;
            }

            try {
                $res = Http::timeout(10)->withHeaders($headers)->post($url, [$this->payload]);
                
                if (!$res->successful()) {
                    Log::warning('AWS forward failed, will retry', [
                        'url' => $url,
                        'status' => $res->status(),
                        'attempt' => $this->attempts(),
                        'playerUUID' => $this->payload['playerUUID'] ?? null,
                        'timestamp' => $this->payload['timestamp'] ?? null
                    ]);
                    throw new \RuntimeException('AWS forward failed: HTTP '.$res->status());
                }
                
                Log::info('Frame forwarded to AWS', [
                    'url' => $url,
                    'playerUUID' => $this->payload['playerUUID'] ?? null,
                    'timestamp' => $this->payload['timestamp'] ?? null,
                    'faceCount' => count($this->payload['faceDetections'] ?? [])
                ]);
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                Log::error('AWS connection failed', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                    'attempt' => $this->attempts(),
                    'playerUUID' => $this->payload['playerUUID'] ?? null
                ]);
                throw new \RuntimeException('AWS connection failed: ' . $e->getMessage(), 0, $e);
            } catch (\Illuminate\Http\Client\RequestException $e) {
                Log::error('AWS request failed', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                    'attempt' => $this->attempts(),
                    'playerUUID' => $this->payload['playerUUID'] ?? null
                ]);
                throw new \RuntimeException('AWS request failed: ' . $e->getMessage(), 0, $e);
            }
        } catch (\RuntimeException $e) {
            // Re-throw RuntimeException to trigger retry mechanism
            throw $e;
        } catch (\Exception $e) {
            Log::error('Unexpected error in ForwardFramesToAws', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'playerUUID' => $this->payload['playerUUID'] ?? null
            ]);
            throw new \RuntimeException('Unexpected error forwarding to AWS', 0, $e);
        }
    }
    
    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ForwardFramesToAws job failed permanently', [
            'error' => $exception->getMessage(),
            'playerUUID' => $this->payload['playerUUID'] ?? null,
            'timestamp' => $this->payload['timestamp'] ?? null,
            'attempts' => $this->attempts()
        ]);
    }
}
