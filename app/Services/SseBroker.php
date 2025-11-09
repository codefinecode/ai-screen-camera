<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class SseBroker extends BaseEventBroker
{
    private const BLOCKING_POP_TIMEOUT = 5;

    /**
     * Get the Redis queue prefix for SSE
     */
    protected function getQueuePrefix(): string
    {
        return 'sse:queue:';
    }

    /**
     * Blocking pop from SSE queue
     *
     * @param string $playerId
     * @param int $timeoutSec
     * @return array<string, mixed>|null
     */
    public function blockingPop(string $playerId, int $timeoutSec = self::BLOCKING_POP_TIMEOUT): ?array
    {
        try {
            $key = $this->getQueuePrefix() . $playerId;
            $res = Redis::blpop([$key], $timeoutSec);
            
            if (!$res) {
                return null;
            }
            
            // BLPOP returns [key, value]
            $payload = $res[1] ?? null;
            if (!$payload) {
                return null;
            }
            
            $arr = json_decode($payload, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::warning('Failed to decode SSE message', [
                    'playerId' => $playerId,
                    'error' => json_last_error_msg()
                ]);
                return null;
            }
            
            return is_array($arr) ? $arr : null;
        } catch (\RedisException $e) {
            Log::error('Redis error in blockingPop', [
                'playerId' => $playerId,
                'error' => $e->getMessage()
            ]);
            return null;
        } catch (\Exception $e) {
            Log::error('Unexpected error in blockingPop', [
                'playerId' => $playerId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}
