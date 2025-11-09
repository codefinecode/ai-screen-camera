<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

abstract class BaseEventBroker
{
    /**
     * Get the Redis queue prefix for this broker
     */
    abstract protected function getQueuePrefix(): string;

    /**
     * Publish an event to the queue
     *
     * @param string $playerId
     * @param string $eventType
     * @param array<string, mixed> $data
     * @return void
     */
    public function publish(string $playerId, string $eventType, array $data): void
    {
        try {
            $message = json_encode([
                'type' => $eventType,
                'data' => $data
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            $key = $this->getQueuePrefix() . $playerId;
            Redis::rpush($key, $message);

            Log::debug('Event published', [
                'broker' => static::class,
                'playerId' => $playerId,
                'eventType' => $eventType,
                'queueKey' => $key
            ]);
        } catch (\JsonException $e) {
            Log::error('Failed to encode event message', [
                'broker' => static::class,
                'playerId' => $playerId,
                'eventType' => $eventType,
                'error' => $e->getMessage()
            ]);
        } catch (\RedisException $e) {
            Log::error('Redis error publishing event', [
                'broker' => static::class,
                'playerId' => $playerId,
                'eventType' => $eventType,
                'error' => $e->getMessage()
            ]);
        } catch (\Exception $e) {
            Log::error('Unexpected error publishing event', [
                'broker' => static::class,
                'playerId' => $playerId,
                'eventType' => $eventType,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
