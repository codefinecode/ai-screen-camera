<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ValidatePayloadSize
{
    public function handle(Request $request, Closure $next): Response
    {
        $maxSizeMb = (int) env('MAX_PAYLOAD_SIZE_MB', 10);
        $maxSizeBytes = $maxSizeMb * 1024 * 1024;
        
        $contentLength = $request->header('Content-Length');
        
        if ($contentLength && (int) $contentLength > $maxSizeBytes) {
            Log::warning('Payload size limit exceeded', [
                'ip' => $request->ip(),
                'path' => $request->path(),
                'contentLength' => $contentLength,
                'maxSize' => $maxSizeBytes
            ]);
            
            return response()->json([
                'error' => 'PAYLOAD_TOO_LARGE',
                'message' => "Payload size exceeds maximum limit of {$maxSizeMb}MB"
            ], 413);
        }
        
        return $next($request);
    }
}
