<?php

namespace App\Http\Controllers;

use App\Jobs\ForwardFramesToAws;
use App\Services\FrameIngestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FramesController extends Controller
{
    public function __construct(private readonly FrameIngestService $ingest) {}

    public function store(Request $request): JsonResponse
    {
        $contentType = $request->header('Content-Type', 'application/json');
        $encoding = $request->header('Content-Encoding');

        $raw = $request->getContent();
        if ($encoding === 'gzip' && function_exists('gzdecode')) {
            $decoded = @gzdecode($raw);
            if (is_string($decoded) && $decoded !== '') {
                $raw = $decoded;
            }
        }

        $frames = [];
        if (str_contains($contentType, 'application/x-ndjson')) {
            $lines = preg_split("/[\r\n]+/", (string) $raw, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($lines as $line) {
                $json = json_decode($line, true);
                if (is_array($json)) $frames[] = $json;
            }
        } else {
            $json = json_decode((string) $raw, true);
            if (is_array($json)) $frames[] = $json;
        }

        if (empty($frames)) {
            return response()->json(['error' => 'INVALID', 'message' => 'No valid frames'], 400);
        }

        $accepted = 0;
        foreach ($frames as $frame) {
            $payload = $this->ingest->processFrame($frame);
            if (!$payload) continue;
            ForwardFramesToAws::dispatch($payload);
            $accepted++;
        }

        if ($accepted === 0) {
            return response()->json(['error' => 'INVALID', 'message' => 'All frames failed validation'], 400);
        }
        return response()->json(['status' => 'ok', 'accepted' => $accepted]);
    }
}
