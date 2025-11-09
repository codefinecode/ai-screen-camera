<?php

namespace App\Http\Controllers;

use App\Http\Requests\PlayerStateRequest;
use App\Services\PlayerStateRepository;
use App\Services\SseBroker;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlayerController extends Controller
{
    public function __construct(private readonly PlayerStateRepository $repo, private readonly SseBroker $sse) {}

    public function state(PlayerStateRequest $request)
    {
        $data = $request->validated()['data'];
        $state = [
            'playerId' => $data['playerId'],
            'content' => $data['content'],
            'timestamp' => $data['timestamp'],
        ];
        $this->repo->setState($data['playerId'], $state);
        // Optional: bind camera if sent via query
        if ($cid = $request->query('cameraId')) {
            $this->repo->bindCamera($cid, $data['playerId']);
        }
        return response()->json(['type' => 'event.ack', 'data' => ['ref' => 'player.state']]);
    }

    public function stream(Request $request)
    {
        $playerId = (string) $request->query('playerId');
        if (!$playerId) return response()->json(['error' => 'INVALID', 'message' => 'playerId required'], 400);
        $response = new StreamedResponse(function () use ($playerId) {
            echo "retry: 3000\n\n";
            $lastBeat = microtime(true);
            while (true) {
                $msg = $this->sse->blockingPop($playerId, 5);
                if ($msg && isset($msg['type'])) {
                    $type = $msg['type'];
                    $data = json_encode($msg['data'] ?? [], JSON_UNESCAPED_UNICODE);
                    echo 'event: ' . $type . "\n";
                    echo 'data: ' . $data . "\n\n";
                    $lastBeat = microtime(true);
                } else {
                    if (microtime(true) - $lastBeat >= 5) {
                        echo 'event: keepalive' . "\n";
                        echo 'data: {}' . "\n\n";
                        $lastBeat = microtime(true);
                    }
                }
                @ob_flush(); @flush();
            }
        });
        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');
        return $response;
    }
}
