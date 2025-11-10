<?php

namespace Tests\Feature;

use App\Contracts\PlayerStateRepositoryInterface;
use App\Jobs\ForwardFramesToAws;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FramesIngestTest extends TestCase
{
    public function test_json_single_frame_is_accepted_and_enqueued(): void
    {
        Queue::fake();

        $this->app->bind(PlayerStateRepositoryInterface::class, function () {
            return new class implements PlayerStateRepositoryInterface {
                public function setState(string $playerId, array $state): void {}
                public function getState(string $playerId): ?array { return ['playerId' => $playerId, 'content' => [['contentId' => '123', 'contentType' => 'media']]]; }
                public function bindCamera(string $cameraId, string $playerId): void {}
                public function resolvePlayerByCamera(?string $cameraId, ?string $playerUUID): ?string { return $playerUUID ?? $cameraId; }
            };
        });

        $payload = [
            'error' => 0,
            'timestamp' => time(),
            'playerUUID' => 'player-1',
            'imgDataBase64' => 'SHOULD_BE_REMOVED',
            'imgWidth' => 100,
            'imgHeight' => 100,
            'faceDetections' => [['faceID' => 1, 'gender' => 0, 'emotion' => 0, 'dwellTime' => 1, 'attentionTime' => 1]],
        ];

        $res = $this->postJson('/api/v1/frames', $payload);
        $res->assertStatus(200)->assertJson(['status' => 'ok', 'accepted' => 1]);

        Queue::assertPushed(ForwardFramesToAws::class, function (ForwardFramesToAws $job) {
            $arr = $job->payload;
            return !isset($arr['imgDataBase64']) && isset($arr['player']) && $arr['player']['playerId'] === 'player-1';
        });
    }

    public function test_ndjson_batch_accepts_two_frames(): void
    {
        Queue::fake();

        $this->app->bind(PlayerStateRepositoryInterface::class, function () {
            return new class implements PlayerStateRepositoryInterface {
                public function setState(string $playerId, array $state): void {}
                public function getState(string $playerId): ?array { return ['playerId' => $playerId, 'content' => [['contentId' => '123', 'contentType' => 'media']]]; }
                public function bindCamera(string $cameraId, string $playerId): void {}
                public function resolvePlayerByCamera(?string $cameraId, ?string $playerUUID): ?string { return $playerUUID ?? $cameraId; }
            };
        });

        $ndjson = json_encode(['error' => 0, 'timestamp' => time(), 'playerUUID' => 'A'])."\n".
                  json_encode(['error' => 0, 'timestamp' => time()+1, 'playerUUID' => 'B'])."\n";

        $res = $this->call(
            'POST',
            '/api/v1/frames',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/x-ndjson'],
            $ndjson
        );

        $res->assertStatus(200)->assertJson(['status' => 'ok', 'accepted' => 2]);

        Queue::assertPushed(ForwardFramesToAws::class, 2);
    }
}
