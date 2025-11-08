<?php

namespace App\Services;

use App\DTO\FrameDto;
use App\Contracts\PlayerStateRepositoryInterface;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class FrameIngestService
{
    public function __construct(
        private readonly PlayerStateRepositoryInterface $stateRepo,
        private readonly TriggerEngine $triggerEngine,
        private readonly SseBroker $sseBroker,
        private readonly WsEventPublisher $wsPublisher,
    ) {}

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'error' => ['nullable', 'integer'],
            'timestamp' => ['required', 'integer'],
            'playerUUID' => ['nullable', 'string'],
            'cameraId' => ['nullable', 'string'],
            'imgDataBase64' => ['nullable', 'string'],
            'imgWidth' => ['nullable', 'integer'],
            'imgHeight' => ['nullable', 'integer'],
            'faceDetections' => ['nullable', 'array'],
            'faceDetections.*.faceID' => ['nullable', 'integer'],
            'faceDetections.*.age' => ['nullable', 'integer'],
            'faceDetections.*.ageConfidence' => ['nullable', 'numeric'],
            'faceDetections.*.gender' => ['nullable', 'integer'],
            'faceDetections.*.genderConfidence' => ['nullable', 'numeric'],
            'faceDetections.*.dwellTime' => ['nullable', 'numeric'],
            'faceDetections.*.attentionTime' => ['nullable', 'numeric'],
            'faceDetections.*.emotion' => ['nullable', 'integer'],
            'faceDetections.*.emotionConfidence' => ['nullable', 'numeric'],
            'faceDetections.*.glasses' => ['nullable'],
            'faceDetections.*.glassesConfidence' => ['nullable', 'numeric'],
            'faceDetections.*.firstTimeSeen' => ['nullable', 'integer'],
            'faceDetections.*.isLastTimeSeen' => ['nullable'],
            'faceDetections.*.x' => ['nullable', 'numeric'],
            'faceDetections.*.y' => ['nullable', 'numeric'],
            'faceDetections.*.width' => ['nullable', 'numeric'],
            'faceDetections.*.height' => ['nullable', 'numeric'],
        ];
    }

    /**
     * @param array<string, mixed> $frame
     * @return array<string, mixed>|null
     */
    public function processFrame(array $frame): ?array
    {
        try {
            $v = Validator::make($frame, $this->rules());
            if ($v->fails()) {
                Log::info('Frame validation failed', [
                    'errors' => $v->errors()->toArray(),
                    'playerUUID' => $frame['playerUUID'] ?? null,
                    'timestamp' => $frame['timestamp'] ?? null
                ]);
                return null;
            }
            $data = $v->validated();
            unset($data['imgDataBase64']);

            $dto = FrameDto::fromArray($data);

            Log::debug('Processing frame', [
                'playerUUID' => $dto->playerUUID,
                'faceCount' => count($dto->faceDetections ?? []),
                'timestamp' => $dto->timestamp
            ]);

            $playerId = $this->stateRepo->resolvePlayerByCamera($dto->cameraId, $dto->playerUUID);
            $player = null;
            if ($playerId) {
                $state = $this->stateRepo->getState($playerId);
                if ($state) {
                    $player = [
                        'playerId' => $state['playerId'],
                        'content' => array_map(fn($c) => ['id' => $c['contentId'], 'type' => $c['contentType']], $state['content'] ?? [])
                    ];
                }
            }

            $payload = $dto->toArray();
            if ($player) $payload['player'] = $player;

            // Retrieve triggers from Redis
            $triggers = null;
            if ($player) {
                try {
                    $triggersJson = \Illuminate\Support\Facades\Redis::get('player:triggers:' . $player['playerId']);
                    if ($triggersJson) {
                        $triggers = json_decode($triggersJson, true);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            Log::warning('Failed to decode triggers JSON', [
                                'playerId' => $player['playerId'],
                                'error' => json_last_error_msg()
                            ]);
                            $triggers = null;
                        }
                    }
                } catch (\RedisException $e) {
                    Log::error('Redis error retrieving triggers', [
                        'playerId' => $player['playerId'],
                        'error' => $e->getMessage()
                    ]);
                    $triggers = null;
                }
            }

            try {
                $decisions = $this->triggerEngine->evaluate($triggers, $dto->toArray(), $player ? ['playerId' => $player['playerId']] : null);
                foreach ($decisions as $d) {
                    try {
                        if ($d['type'] === 'start') {
                            Log::info('event.triggerStart', ['id' => $d['id'], 'playerId' => $d['playerId']]);
                            $this->sseBroker->publish($d['playerId'], 'event.triggerStart', ['id' => $d['id']]);
                            $this->wsPublisher->publish($d['playerId'], 'event.triggerStart', ['id' => $d['id']]);
                        } else {
                            Log::info('event.triggerEnd', ['id' => $d['id'], 'playerId' => $d['playerId']]);
                            $this->sseBroker->publish($d['playerId'], 'event.triggerEnd', ['id' => $d['id']]);
                            $this->wsPublisher->publish($d['playerId'], 'event.triggerEnd', ['id' => $d['id']]);
                        }
                    } catch (\Exception $e) {
                        Log::error('Failed to publish trigger event', [
                            'triggerId' => $d['id'] ?? null,
                            'playerId' => $d['playerId'] ?? null,
                            'type' => $d['type'] ?? null,
                            'error' => $e->getMessage()
                        ]);
                        // Continue processing other decisions even if one fails
                    }
                }
            } catch (\Exception $e) {
                Log::error('TriggerEngine evaluation failed', [
                    'playerUUID' => $dto->playerUUID,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                // Continue with frame forwarding even if trigger evaluation fails
            }

            return $payload;
        } catch (\Exception $e) {
            Log::error('Frame processing failed', [
                'playerUUID' => $frame['playerUUID'] ?? null,
                'timestamp' => $frame['timestamp'] ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }
}
