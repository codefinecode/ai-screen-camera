<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ApiAuthentication
{
    public function handle(Request $request, Closure $next): Response
    {
        // Skip authentication in local environment
        if (app()->environment('local')) {
            return $next($request);
        }
        
        $expectedToken = env('API_BEARER_TOKEN');
        
        // If no token is configured, skip authentication
        if (!$expectedToken) {
            return $next($request);
        }
        
        $authHeader = $request->header('Authorization');
        
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            Log::warning('Missing or invalid Authorization header', [
                'ip' => $request->ip(),
                'path' => $request->path()
            ]);
            
            return response()->json([
                'error' => 'UNAUTHORIZED',
                'message' => 'Authentication required'
            ], 401);
        }
        
        $token = substr($authHeader, 7); // Remove 'Bearer ' prefix
        
        // Use hash_equals to prevent timing attacks
        if (!hash_equals($expectedToken, $token)) {
            Log::warning('Invalid API token', [
                'ip' => $request->ip(),
                'path' => $request->path()
            ]);
            
            return response()->json([
                'error' => 'UNAUTHORIZED',
                'message' => 'Invalid authentication credentials'
            ], 401);
        }
        
        return $next($request);
    }
}
