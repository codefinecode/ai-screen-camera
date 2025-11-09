<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\TriggerEngineInterface;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class TriggerEngine implements TriggerEngineInterface
{
    private const DEFAULT_THROTTLE_MS = 300;
    private const ACTIVE_TRIGGER_TTL = 3600; // 1 hour

    private int $throttleMs;

    public function __construct()
    {
        $this->throttleMs = (int) config('trigger.throttle_ms', self::DEFAULT_THROTTLE_MS);
    }

    /**
     * Evaluate face detections against trigger rules
     * 
     * @param array<int, array<string, mixed>>|null $triggers Trigger rules from player
     * @param array<string, mixed> $frame Frame data with faceDetections
     * @param array<string, mixed>|null $playerState Player state
     * @return array<int, array<string, mixed>>
     */
    public function evaluate(?array $triggers, array $frame, ?array $playerState): array
    {
        $faces = $frame['faceDetections'] ?? [];
        
        if (!$playerState || empty($faces) || empty($triggers)) {
            return [];
        }

        $playerId = $playerState['playerId'] ?? null;
        if (!$playerId) {
            return [];
        }

        $decisions = [];

        try {
            // Get currently active triggers for this player
            $activeTriggers = $this->getActiveTriggers($playerId);

            // Track which triggers matched in this frame
            $matchedTriggers = [];

            // Evaluate each face against each trigger
            foreach ($faces as $face) {
                $faceId = (int) ($face['faceID'] ?? 0);
                
                foreach ($triggers as $trigger) {
                    $triggerId = (string) ($trigger['id'] ?? '');
                    if ($triggerId === '') {
                        continue;
                    }

                    $activeKey = "{$triggerId}:{$faceId}";
                    $isActive = isset($activeTriggers[$activeKey]);

                    // Check if face matches trigger conditions
                    if ($this->matchesTrigger($face, $trigger)) {
                        $matchedTriggers[$activeKey] = true;

                        // Only send triggerStart if not already active and not throttled
                        if (!$isActive && !$this->isThrottled($playerId, $triggerId, $faceId)) {
                            $decisions[] = [
                                'type' => 'start',
                                'id' => $triggerId,
                                'playerId' => $playerId
                            ];

                            $this->setActiveTrigger($playerId, $triggerId, $faceId);
                            $this->setThrottle($playerId, $triggerId, $faceId);

                            Log::debug('Trigger activated', [
                                'playerId' => $playerId,
                                'triggerId' => $triggerId,
                                'faceId' => $faceId
                            ]);
                        }
                    } else {
                        // Face no longer matches - send triggerEnd if it was active
                        if ($isActive) {
                            $decisions[] = [
                                'type' => 'end',
                                'id' => $triggerId,
                                'playerId' => $playerId
                            ];

                            $this->removeActiveTrigger($playerId, $triggerId, $faceId);

                            Log::debug('Trigger deactivated', [
                                'playerId' => $playerId,
                                'triggerId' => $triggerId,
                                'faceId' => $faceId
                            ]);
                        }
                    }
                }
            }

            // Check for triggers that were active but no longer matched by any face
            foreach ($activeTriggers as $activeKey => $timestamp) {
                if (!isset($matchedTriggers[$activeKey])) {
                    [$triggerId, $faceId] = explode(':', $activeKey, 2);
                    
                    $decisions[] = [
                        'type' => 'end',
                        'id' => $triggerId,
                        'playerId' => $playerId
                    ];

                    $this->removeActiveTrigger($playerId, $triggerId, (int) $faceId);

                    Log::debug('Trigger ended (face disappeared)', [
                        'playerId' => $playerId,
                        'triggerId' => $triggerId,
                        'faceId' => $faceId
                    ]);
                }
            }

        } catch (\Exception $e) {
            Log::error('Error evaluating triggers', [
                'playerId' => $playerId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }

        return $decisions;
    }

    /**
     * Check if a face matches all conditions of a trigger
     * 
     * @param array<string, mixed> $face
     * @param array<string, mixed> $trigger
     * @return bool
     */
    private function matchesTrigger(array $face, array $trigger): bool
    {
        // Age range check
        if (isset($trigger['age']) && is_array($trigger['age']) && count($trigger['age']) === 2) {
            $age = $face['age'] ?? null;
            if ($age === null || $age < $trigger['age'][0] || $age > $trigger['age'][1]) {
                return false;
            }
            
            // Age confidence check
            if (isset($trigger['ageConfidence'])) {
                $conf = (float) ($face['ageConfidence'] ?? 0);
                if ($conf < (float) $trigger['ageConfidence']) {
                    return false;
                }
            }
        }

        // Gender check
        if (isset($trigger['gender'])) {
            $expectedGender = $trigger['gender'] === 'male' ? 0 : 1;
            $faceGender = $face['gender'] ?? null;
            
            if ($faceGender !== $expectedGender) {
                return false;
            }
            
            // Gender confidence check
            if (isset($trigger['genderConfidence'])) {
                $conf = (float) ($face['genderConfidence'] ?? 0);
                if ($conf < (float) $trigger['genderConfidence']) {
                    return false;
                }
            }
        }

        // Emotion check
        if (isset($trigger['emotion']) && is_array($trigger['emotion'])) {
            $emotion = $face['emotion'] ?? null;
            if (!in_array($emotion, $trigger['emotion'], true)) {
                return false;
            }
            
            // Emotion confidence check
            if (isset($trigger['emotionConfidence'])) {
                $conf = (float) ($face['emotionConfidence'] ?? 0);
                if ($conf < (float) $trigger['emotionConfidence']) {
                    return false;
                }
            }
        }

        // DwellTime check
        if (isset($trigger['dwellTime'])) {
            $dwell = (int) ($face['dwellTime'] ?? 0);
            if ($dwell < (int) $trigger['dwellTime']) {
                return false;
            }
        }

        // AttentionTime check
        if (isset($trigger['attentionTime'])) {
            $att = (int) ($face['attentionTime'] ?? 0);
            if ($att < (int) $trigger['attentionTime']) {
                return false;
            }
        }

        // Glasses check
        if (isset($trigger['glasses'])) {
            $expectedGlasses = $trigger['glasses'] === 'glasses' ? 1 : 0;
            $faceGlasses = $face['glasses'] ?? null;
            
            if ($faceGlasses !== $expectedGlasses) {
                return false;
            }
            
            // Glasses confidence check
            if (isset($trigger['glassesConfidence'])) {
                $conf = (float) ($face['glassesConfidence'] ?? 0);
                if ($conf < (float) $trigger['glassesConfidence']) {
                    return false;
                }
            }
        }

        // FirstSeen check
        if (isset($trigger['firstSeen']) && $trigger['firstSeen'] === true) {
            $isLastTimeSeen = (int) ($face['isLastTimeSeen'] ?? 0);
            if ($isLastTimeSeen !== 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if trigger is currently throttled
     * 
     * @param string $playerId
     * @param string $triggerId
     * @param int $faceId
     * @return bool
     */
    private function isThrottled(string $playerId, string $triggerId, int $faceId): bool
    {
        try {
            $key = "trigger:throttle:{$playerId}:{$triggerId}:{$faceId}";
            $timestamp = Redis::get($key);
            
            if ($timestamp === null) {
                return false;
            }

            $lastTriggerTime = (int) $timestamp;
            $currentTime = (int) (microtime(true) * 1000);
            
            return ($currentTime - $lastTriggerTime) < $this->throttleMs;
            
        } catch (\Exception $e) {
            Log::error('Error checking throttle', [
                'playerId' => $playerId,
                'triggerId' => $triggerId,
                'faceId' => $faceId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Set throttle timestamp for trigger
     * 
     * @param string $playerId
     * @param string $triggerId
     * @param int $faceId
     * @return void
     */
    private function setThrottle(string $playerId, string $triggerId, int $faceId): void
    {
        try {
            $key = "trigger:throttle:{$playerId}:{$triggerId}:{$faceId}";
            $timestamp = (int) (microtime(true) * 1000);
            $ttlSeconds = (int) ceil($this->throttleMs / 1000);
            
            Redis::setex($key, $ttlSeconds, (string) $timestamp);
            
        } catch (\Exception $e) {
            Log::error('Error setting throttle', [
                'playerId' => $playerId,
                'triggerId' => $triggerId,
                'faceId' => $faceId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get all active triggers for a player
     * 
     * @param string $playerId
     * @return array<string, int> Map of "triggerId:faceId" => timestamp
     */
    private function getActiveTriggers(string $playerId): array
    {
        try {
            $pattern = "trigger:active:{$playerId}:*";
            $keys = Redis::keys($pattern);
            
            $active = [];
            foreach ($keys as $key) {
                // Extract triggerId:faceId from key
                $parts = explode(':', $key);
                if (count($parts) >= 4) {
                    $triggerId = $parts[3];
                    $faceId = $parts[4] ?? '';
                    $activeKey = "{$triggerId}:{$faceId}";
                    
                    $timestamp = Redis::get($key);
                    $active[$activeKey] = (int) ($timestamp ?? 0);
                }
            }
            
            return $active;
            
        } catch (\Exception $e) {
            Log::error('Error getting active triggers', [
                'playerId' => $playerId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Mark trigger as active
     * 
     * @param string $playerId
     * @param string $triggerId
     * @param int $faceId
     * @return void
     */
    private function setActiveTrigger(string $playerId, string $triggerId, int $faceId): void
    {
        try {
            $key = "trigger:active:{$playerId}:{$triggerId}:{$faceId}";
            $timestamp = (int) (microtime(true) * 1000);
            
            Redis::setex($key, self::ACTIVE_TRIGGER_TTL, (string) $timestamp);
            
        } catch (\Exception $e) {
            Log::error('Error setting active trigger', [
                'playerId' => $playerId,
                'triggerId' => $triggerId,
                'faceId' => $faceId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Remove active trigger
     * 
     * @param string $playerId
     * @param string $triggerId
     * @param int $faceId
     * @return void
     */
    private function removeActiveTrigger(string $playerId, string $triggerId, int $faceId): void
    {
        try {
            $key = "trigger:active:{$playerId}:{$triggerId}:{$faceId}";
            Redis::del($key);
            
        } catch (\Exception $e) {
            Log::error('Error removing active trigger', [
                'playerId' => $playerId,
                'triggerId' => $triggerId,
                'faceId' => $faceId,
                'error' => $e->getMessage()
            ]);
        }
    }
}
