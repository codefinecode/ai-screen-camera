<?php

namespace App\Services;

use App\Contracts\AwsFramesReaderInterface;
use Illuminate\Support\Facades\Http;

class AwsFramesReader implements AwsFramesReaderInterface
{
    public function fetchFrames(array $filters): array
    {
        $url = config('services.aws.query_url');
        if (!$url) {
            return [];
        }
        $token = config('services.aws.bearer_token');
        $headers = ['Accept' => 'application/json'];
        if ($token) {
            $headers['Authorization'] = 'Bearer '.$token;
        }
        $res = Http::timeout(15)->withHeaders($headers)->get($url, $filters);
        if (!$res->successful()) {
            return [];
        }
        $body = $res->json();
        return $body['frames'] ?? ($body['data'] ?? []);
    }
}
