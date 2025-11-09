<?php

declare(strict_types=1);

namespace App\Services;

class WsEventPublisher extends BaseEventBroker
{
    /**
     * Get the Redis queue prefix for WebSocket
     */
    protected function getQueuePrefix(): string
    {
        return 'ws:queue:';
    }
}
