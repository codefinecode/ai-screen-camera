<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Trigger Throttle Time
    |--------------------------------------------------------------------------
    |
    | The minimum time (in milliseconds) between duplicate trigger events
    | for the same trigger and face. This prevents flooding the player
    | with duplicate events.
    |
    */
    'throttle_ms' => (int) env('TRIGGER_THROTTLE_MS', 300),

    /*
    |--------------------------------------------------------------------------
    | Active Trigger TTL
    |--------------------------------------------------------------------------
    |
    | The time-to-live (in seconds) for active trigger state in Redis.
    | After this time, the trigger state will be automatically cleaned up.
    |
    */
    'active_ttl' => (int) env('TRIGGER_ACTIVE_TTL', 3600),
];
