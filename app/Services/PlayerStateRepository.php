<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\PlayerStateRepositoryInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use JsonException;
use RedisException;

class PlayerStateRepository implements PlayerStateRepositoryInterface
{
    private const STATE_KEY_PREFIX = 'player:state:';
    private const CAMERA_MAP_KEY = 'camera:player';

    /**
     * Set player state in Redis
     *
     * @param string $playerId
     * @param array<string, mixed> $state
     * @throws \RuntimeException
     */
    public function setState(string $playerId, array $state): void
    {
        try {
            $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            Redis::set(self::STATE_KEY_PREFIX . $playerId, $json);

            Log::debug('Player state updated', [
                'playerId' => $playerId,
                'contentCount' => count($state['content'] ?? [])
            ]);

        } catch (JsonException $e) {
            Log::error('Failed to encode player state', [
                'playerId' => $playerId,
                'operation' => 'setState',
                'error' => $e->getMessage()
            ]);
            throw new \RuntimeException('Failed to encode player state', 0, $e);

        } catch (RedisException $e) {
            Log::error('Redis error in setState', [
                'playerId' => $playerId,
                'operation' => 'setState',
                'error' => $e->getMessage()
            ]);
            throw new \RuntimeException('Failed to save player state', 0, $e);
        }
    }

    /**
     * Get player state from Redis
     *
     * @param string $playerId
     * @return array<string, mixed>|null
     */
    public function getState(string $playerId): ?array
    {
        try {
            $raw = Redis::get(self::STATE_KEY_PREFIX . $playerId);
            if (!$raw) {
                return null;
            }

            $data = json_decode($raw, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::warning('Failed to decode player state', [
                    'playerId' => $playerId,
                    'operation' => 'getState',
                    'error' => json_last_error_msg(),
                    'raw' => substr($raw, 0, 100) // First 100 chars for debugging
                ]);
                return null;
            }

            return $data;

        } catch (RedisException $e) {
            Log::error('Redis error in getState', [
                'playerId' => $playerId,
                'operation' => 'getState',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Bind camera ID to player ID
     *
     * @param string $cameraId
     * @param string $playerId
     */
    public function bindCamera(string $cameraId, string $playerId): void
    {
        try {
            Redis::hset(self::CAMERA_MAP_KEY, $cameraId, $playerId);

            Log::debug('Camera bound to player', [
                'cameraId' => $cameraId,
                'playerId' => $playerId,
                'operation' => 'bindCamera'
            ]);

        } catch (RedisException $e) {
            Log::error('Redis error in bindCamera', [
                'cameraId' => $cameraId,
                'playerId' => $playerId,
                'operation' => 'bindCamera',
                'error' => $e->getMessage()
            ]);
            throw new \RuntimeException('Failed to bind camera to player', 0, $e);
        }
    }

    /**
     * Resolve player ID by camera ID or player UUID
     *
     * @param string|null $cameraId
     * @param string|null $playerUUID
     * @return string|null
     */
    public function resolvePlayerByCamera(?string $cameraId, ?string $playerUUID): ?string
    {
        try {
            if ($playerUUID) {
                $state = $this->getState($playerUUID);
                if ($state) {
                    return $playerUUID;
                }
            }

            if ($cameraId) {
                $playerId = Redis::hget(self::CAMERA_MAP_KEY, $cameraId);
                if ($playerId) {
                    return $playerId;
                }
            }

            return null;

        } catch (RedisException $e) {
            Log::error('Redis error in resolvePlayerByCamera', [
                'cameraId' => $cameraId,
                'playerUUID' => $playerUUID,
                'operation' => 'resolvePlayerByCamera',
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}
