<?php

namespace Tests\Feature;

use App\Contracts\AwsFramesReaderInterface;
use Tests\TestCase;

class DashboardsAggregationTest extends TestCase
{
    public function test_returns_aggregated_when_returnRawFrames_false(): void
    {
        $frames = [
            [
                'timestamp' => strtotime('2025-01-01T00:00:00Z'),
                'playerUUID' => 'screen-1',
                'player' => ['playerId' => 'player-1', 'content' => [['id' => '123', 'type' => 'media']]],
                'faceDetections' => [
                    ['faceID' => 1, 'gender' => 0, 'emotion' => 0, 'dwellTime' => 1000, 'attentionTime' => 500, 'age' => 29, 'glasses' => 1],
                ],
            ],
        ];

        $this->app->bind(AwsFramesReaderInterface::class, function () use ($frames) {
            return new class($frames) implements AwsFramesReaderInterface {
                public function __construct(private array $f) {}
                public function fetchFrames(array $filters): array { return $this->f; }
            };
        });

        $res = $this->getJson('/api/dashboards/frames?filter[start]=2025-01-01T00:00:00Z&filter[end]=2025-01-01T12:00:00Z&filter[screenIds]=screen-1');
        $res->assertStatus(200)->assertJsonStructure(['bucketType','totals'=>['faces','views','impressions','gender','emotion','glasses','dwellTime','attentionTime'],'buckets']);
    }
}
