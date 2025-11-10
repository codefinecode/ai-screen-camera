<?php

namespace Tests\Feature;

use App\Contracts\AwsFramesReaderInterface;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class DashboardsMoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Cache::flush();
    }

    public function test_return_raw_frames_sorted_by_timestamp(): void
    {
        $frames = [
            ['timestamp' => 200, 'playerUUID' => 's1'],
            ['timestamp' => 100, 'playerUUID' => 's1'],
        ];
        
        $this->mockAwsReader($frames);

        $res = $this->getJson('/api/dashboards/frames?filter[start]=1970-01-01T00:00:00Z&filter[end]=2030-01-01T00:00:00Z&filter[screenIds]=s1&returnRawFrames=true');
        $res->assertStatus(200);
        $data = $res->json('frames');
        $this->assertCount(2, $data, 'Should have 2 frames');
        $this->assertSame(100, $data[0]['timestamp']);
        $this->assertSame(200, $data[1]['timestamp']);
    }

    public function test_views_respect_impression_gap_sec(): void
    {
        Config::set('aggregation.cache_ttl', 0);
        Config::set('aggregation.impression_gap_sec', 5);

        $base = strtotime('2025-01-01T00:00:00Z');
        $frames = [
            [
                'timestamp' => $base,
                'playerUUID' => 'screen-1',
                'player' => ['playerId' => 'p1', 'content' => [['id' => 'A', 'type' => 'media']]],
                'faceDetections' => [['faceID' => 1]]
            ],
            [
                'timestamp' => $base + 3,
                'playerUUID' => 'screen-1',
                'player' => ['playerId' => 'p1', 'content' => [['id' => 'A', 'type' => 'media']]],
                'faceDetections' => [['faceID' => 1]]
            ],
            [
                'timestamp' => $base + 6,
                'playerUUID' => 'screen-1',
                'player' => ['playerId' => 'p1', 'content' => [['id' => 'A', 'type' => 'media']]],
                'faceDetections' => [['faceID' => 1]]
            ],
        ];
        $this->mockAwsReader($frames);

        $res = $this->getJson('/api/dashboards/frames?filter[start]=2025-01-01T00:00:00Z&filter[end]=2025-01-01T00:10:00Z&filter[screenIds]=screen-1');
        $res->assertStatus(200);
        
        $views = $res->json('totals.views');
        $this->assertSame(2, $views, 'Expected 2 views but got ' . $views . '. Response: ' . json_encode($res->json('totals')));
    }

    public function test_age_bins_cover_boundaries(): void
    {
        $base = strtotime('2025-01-01T00:00:00Z');
        $frames = [
            ['timestamp' => $base, 'playerUUID' => 's1', 'player' => ['playerId' => 'p1', 'content' => []], 'faceDetections' => [ ['age' => 19] ]],
            ['timestamp' => $base, 'playerUUID' => 's1', 'player' => ['playerId' => 'p1', 'content' => []], 'faceDetections' => [ ['age' => 20] ]],
            ['timestamp' => $base, 'playerUUID' => 's1', 'player' => ['playerId' => 'p1', 'content' => []], 'faceDetections' => [ ['age' => 29] ]],
            ['timestamp' => $base, 'playerUUID' => 's1', 'player' => ['playerId' => 'p1', 'content' => []], 'faceDetections' => [ ['age' => 30] ]],
            ['timestamp' => $base, 'playerUUID' => 's1', 'player' => ['playerId' => 'p1', 'content' => []], 'faceDetections' => [ ['age' => 45] ]],
            ['timestamp' => $base, 'playerUUID' => 's1', 'player' => ['playerId' => 'p1', 'content' => []], 'faceDetections' => [ ['age' => 46] ]],
        ];
        $this->mockAwsReader($frames);

        $res = $this->getJson('/api/dashboards/frames?filter[start]=2025-01-01T00:00:00Z&filter[end]=2025-01-02T00:00:00Z&filter[screenIds]=s1');
        $res->assertStatus(200);
        $buckets = $res->json('buckets');
        $this->assertNotEmpty($buckets);
        $ageBins = $buckets[0]['ageBins'];
        $this->assertSame(1, $ageBins['<20']);   // 19
        $this->assertSame(2, $ageBins['20-29']); // 20,29
        $this->assertSame(2, $ageBins['30-45']); // 30,45
        $this->assertSame(1, $ageBins['45+']);   // 46
    }

    private function mockAwsReader(array $frames): void
    {
        $this->app->forgetInstance(AwsFramesReaderInterface::class);
        $this->app->bind(AwsFramesReaderInterface::class, function () use ($frames) {
            return new class($frames) implements AwsFramesReaderInterface {
                public function __construct(private array $f) {}
                public function fetchFrames(array $filters): array { 
                    return $this->f; 
                }
            };
        });
    }
}
