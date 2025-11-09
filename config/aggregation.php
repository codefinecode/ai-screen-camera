<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Impression Gap (seconds)
    |--------------------------------------------------------------------------
    |
    | Time gap in seconds between views for the same content and player.
    | If frames arrive within this gap, they count as the same view.
    |
    */
    'impression_gap_sec' => (int) env('IMPRESSION_GAP_SEC', 5),

    /*
    |--------------------------------------------------------------------------
    | Cache TTL (seconds)
    |--------------------------------------------------------------------------
    |
    | How long to cache aggregation results.
    |
    */
    'cache_ttl' => (int) env('AGGREGATION_CACHE_TTL', 300),

    /*
    |--------------------------------------------------------------------------
    | Max Frames Limit
    |--------------------------------------------------------------------------
    |
    | Maximum number of frames that can be aggregated in a single request.
    |
    */
    'max_frames' => (int) env('AGGREGATION_MAX_FRAMES', 10000),
];
