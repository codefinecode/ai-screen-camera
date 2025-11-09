<?php

namespace App\WebSocket;

use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;
use SplObjectStorage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use React\EventLoop\LoopInterface;

class PlayerWsServer implements MessageComponentInterface
{
    private const MAX_MESSAGES_PER_TICK = 10;
    private const DRAIN_INTERVAL_SEC = 0.5;

    /** @var SplObjectStorage<ConnectionInterface, array{playerId?:string}> */
    private SplObjectStorage $clients;

    private LoopInterface $loop;

    public function __construct(LoopInterface $loop)
    {
        $this->clients = new SplObjectStorage();
        $this->loop = $loop;
        
        // Create single global periodic timer for draining all message queues
        $this->loop->addPeriodicTimer(self::DRAIN_INTERVAL_SEC, function () {
            $this->drainAllQueues();
        });
        
        Log::info('PlayerWsServer initialized with global drain timer');
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        $this->clients[$conn] = [];
        Log::debug('WebSocket connection opened', [
            'resourceId' => $conn->resourceId ?? 'unknown',
            'totalConnections' => $this->clients->count()
        ]);
    }
    
    /**
     * Drain message queues for all connected clients
     */
    private function drainAllQueues(): void
    {
        try {
            foreach ($this->clients as $conn) {
                $ctx = $this->clients[$conn];
                $playerId = $ctx['playerId'] ?? null;
                
                if (!$playerId) {
                    continue;
                }
                
                try {
                    $this->drainQueue($conn, $playerId);
                } catch (\Exception $e) {
                    Log::error('Failed to drain queue for connection', [
                        'playerId' => $playerId,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('Error in drainAllQueues', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
    
    /**
     * Drain messages from Redis queue for a specific connection
     */
    private function drainQueue(ConnectionInterface $conn, string $playerId): void
    {
        $key = 'ws:queue:' . $playerId;
        
        try {
            for ($i = 0; $i < self::MAX_MESSAGES_PER_TICK; $i++) {
                $payload = Redis::lpop($key);
                if (!$payload) {
                    break;
                }
                
                try {
                    $conn->send($payload);
                } catch (\Exception $e) {
                    Log::warning('Failed to send message to WebSocket client', [
                        'playerId' => $playerId,
                        'error' => $e->getMessage()
                    ]);
                    break;
                }
            }
        } catch (\RedisException $e) {
            Log::error('Redis error in drainQueue', [
                'playerId' => $playerId,
                'error' => $e->getMessage()
            ]);
        }
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        try {
            $data = json_decode((string)$msg, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::warning('Invalid JSON in WebSocket message', [
                    'error' => json_last_error_msg(),
                    'resourceId' => $from->resourceId ?? 'unknown'
                ]);
                return;
            }
            
            $type = $data['type'] ?? '';
            $payload = $data['data'] ?? [];

            if ($type === 'player.hello') {
                $this->handleHello($from, $payload);
                return;
            }
            
            if ($type === 'player.state') {
                $this->handleState($from, $payload);
                return;
            }
            
            if ($type === 'player.triggers') {
                $this->handleTriggers($from, $payload);
                return;
            }

            // Unknown types ignored
            Log::debug('Unknown WebSocket message type', [
                'type' => $type,
                'resourceId' => $from->resourceId ?? 'unknown'
            ]);
        } catch (\Exception $e) {
            Log::error('Error processing WebSocket message', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'resourceId' => $from->resourceId ?? 'unknown'
            ]);
        }
    }
    
    /**
     * Handle player.hello message
     */
    private function handleHello(ConnectionInterface $conn, array $payload): void
    {
        $playerId = (string)($payload['playerId'] ?? '');
        
        if ($playerId !== '') {
            $this->clients[$conn] = ['playerId' => $playerId];
            
            Log::info('Player connected via WebSocket', [
                'playerId' => $playerId,
                'resourceId' => $conn->resourceId ?? 'unknown'
            ]);
        }
        
        try {
            $conn->send(json_encode([
                'type' => 'event.ack',
                'data' => ['ref' => 'player.hello']
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } catch (\Exception $e) {
            Log::error('Failed to send hello ack', [
                'playerId' => $playerId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Handle player.state message
     */
    private function handleState(ConnectionInterface $conn, array $payload): void
    {
        $playerId = (string)($payload['playerId'] ?? '');
        
        if ($playerId !== '') {
            $this->clients[$conn] = ['playerId' => $playerId];
            
            try {
                $state = [
                    'playerId' => $playerId,
                    'content' => $payload['content'] ?? [],
                    'timestamp' => (int)($payload['timestamp'] ?? time()),
                ];
                
                Redis::set(
                    'player:state:' . $playerId,
                    json_encode($state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                );
                
                Log::debug('Player state updated via WebSocket', [
                    'playerId' => $playerId,
                    'contentCount' => count($state['content'])
                ]);
            } catch (\RedisException $e) {
                Log::error('Redis error saving player state', [
                    'playerId' => $playerId,
                    'error' => $e->getMessage()
                ]);
            } catch (\JsonException $e) {
                Log::error('JSON encoding error for player state', [
                    'playerId' => $playerId,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        try {
            $conn->send(json_encode([
                'type' => 'event.ack',
                'data' => ['ref' => 'player.state']
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } catch (\Exception $e) {
            Log::error('Failed to send state ack', [
                'playerId' => $playerId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Handle player.triggers message
     */
    private function handleTriggers(ConnectionInterface $conn, array $payload): void
    {
        $playerId = (string)($payload['playerId'] ?? '');
        $triggers = $payload['triggers'] ?? [];
        
        if ($playerId === '') {
            Log::warning('Received player.triggers without playerId');
            return;
        }
        
        if (!is_array($triggers)) {
            Log::warning('Invalid triggers format', [
                'playerId' => $playerId,
                'type' => gettype($triggers)
            ]);
            return;
        }
        
        // Validate triggers structure
        $validTriggers = [];
        foreach ($triggers as $trigger) {
            if (!is_array($trigger)) {
                continue;
            }
            
            // Basic validation - id is required
            if (!isset($trigger['id']) || !is_string($trigger['id'])) {
                Log::warning('Trigger missing id field', [
                    'playerId' => $playerId,
                    'trigger' => $trigger
                ]);
                continue;
            }
            
            $validTriggers[] = $trigger;
        }
        
        try {
            // Store triggers in Redis for TriggerEngine to use
            $key = 'player:triggers:' . $playerId;
            Redis::set(
                $key,
                json_encode($validTriggers, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            );
            
            Log::info('Player triggers updated via WebSocket', [
                'playerId' => $playerId,
                'triggerCount' => count($validTriggers)
            ]);
            
            // Send acknowledgment
            $conn->send(json_encode([
                'type' => 'event.ack',
                'data' => [
                    'ref' => 'player.triggers',
                    'accepted' => count($validTriggers),
                    'rejected' => count($triggers) - count($validTriggers)
                ]
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            
        } catch (\RedisException $e) {
            Log::error('Redis error saving triggers', [
                'playerId' => $playerId,
                'error' => $e->getMessage()
            ]);
            
            try {
                $conn->send(json_encode([
                    'type' => 'event.error',
                    'data' => [
                        'ref' => 'player.triggers',
                        'error' => 'Failed to save triggers'
                    ]
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            } catch (\Exception $sendError) {
                Log::error('Failed to send error response', [
                    'error' => $sendError->getMessage()
                ]);
            }
        } catch (\JsonException $e) {
            Log::error('JSON encoding error for triggers', [
                'playerId' => $playerId,
                'error' => $e->getMessage()
            ]);
        } catch (\Exception $e) {
            Log::error('Unexpected error handling triggers', [
                'playerId' => $playerId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    public function onClose(ConnectionInterface $conn): void
    {
        if (isset($this->clients[$conn])) {
            $ctx = $this->clients[$conn];
            $playerId = $ctx['playerId'] ?? null;
            
            unset($this->clients[$conn]);
            
            Log::debug('WebSocket connection closed', [
                'resourceId' => $conn->resourceId ?? 'unknown',
                'playerId' => $playerId,
                'remainingConnections' => $this->clients->count()
            ]);
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        Log::warning('WS error', ['msg' => $e->getMessage()]);
        $conn->close();
    }
}
