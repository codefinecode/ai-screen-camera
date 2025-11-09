<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

class MetricsController extends Controller
{
    public function metrics()
    {
        $metrics = [
            'uptimeSec' => (int) (microtime(true) - LARAVEL_START),
            'queue' => [
                'connection' => config('queue.default'),
            ],
        ];
        return response()->json($metrics);
    }
}
