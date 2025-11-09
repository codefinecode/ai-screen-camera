<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class AggregationService
{
    private const IMPRESSION_GAP_SEC = 5;
    private const CACHE_TTL_SECONDS = 300; // 5 minutes
    private const MAX_FRAMES_LIMIT = 10000;

    /**
     * Aggregate frames data into buckets with totals
     *
     * @param array<int, array<string, mixed>> $frames
     * @param string $startIso
     * @param string $endIso
     * @param string|null $bucketType
     * @return array<string, mixed>
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function aggregate(array $frames, string $startIso, string $endIso, ?string $bucketType = null): array
    {
        $startTime = microtime(true);
        
        $startTs = strtotime($startIso);
        $endTs = strtotime($endIso);
        
        if ($startTs === false || $endTs === false) {
            throw new \InvalidArgumentException('Invalid date format for start or end time');
        }
        
        if ($startTs >= $endTs) {
            throw new \InvalidArgumentException('Start time must be before end time');
        }
        
        // Check frame count limit
        $frameCount = count($frames);
        if ($frameCount > self::MAX_FRAMES_LIMIT) {
            throw new \RuntimeException(
                "Frame count ({$frameCount}) exceeds maximum limit (" . self::MAX_FRAMES_LIMIT . ")"
            );
        }
        
        // Generate cache key
        $cacheKey = $this->generateCacheKey($frames, $startIso, $endIso, $bucketType);
        
        // Try to get from cache (if TTL > 0)
        $cacheTtl = (int) config('aggregation.cache_ttl', self::CACHE_TTL_SECONDS);
        if ($cacheTtl > 0) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                Log::debug('Aggregation cache hit', [
                    'cacheKey' => $cacheKey,
                    'frameCount' => $frameCount
                ]);
                return $cached;
            }
        }
        
        $diff = $endTs - $startTs;
        $bucket = $bucketType ?? $this->autoBucket($diff);

        $buckets = $this->makeBuckets($startTs, $endTs, $bucket);
        $totals = [
            'faces' => 0,
            'views' => 0,
            'impressions' => 0,
            'gender' => ['male' => 0, 'female' => 0],
            'emotion' => ['Happy' => 0, 'Satisfied' => 0, 'Neutral' => 0, 'Unhappy' => 0],
            'glasses' => ['with' => 0, 'without' => 0],
            'dwellTime' => ['sum' => 0, 'avg' => 0],
            'attentionTime' => ['sum' => 0, 'avg' => 0],
        ];

        $viewGap = (int) config('aggregation.impression_gap_sec', self::IMPRESSION_GAP_SEC);
        $lastViewTs = [];
        $impressionSet = [];

        foreach ($frames as $frame) {
            try {
                $ts = (int) ($frame['timestamp'] ?? 0);
                if ($ts < $startTs || $ts >= $endTs) continue;

                $bucketKey = $this->bucketKeyFor($ts, $bucket, $startTs, $endTs);
                if (!isset($buckets[$bucketKey])) continue;

                $playerUUID = $frame['playerUUID'] ?? null;
                $contentIds = array_map(fn($c) => $c['id'] ?? $c['contentId'] ?? null, $frame['player']['content'] ?? []);
                $contentIds = array_values(array_filter($contentIds));

                $faces = $frame['faceDetections'] ?? [];
                $totals['faces'] += count($faces);

                // VIEW counting per (contentId, playerUUID) with GAP
                foreach ($contentIds as $cid) {
                    $key = $playerUUID.'|'.$cid;
                    $last = $lastViewTs[$key] ?? 0;
                    if ($ts - $last >= $viewGap) {
                        $totals['views'] += 1;
                        $lastViewTs[$key] = $ts;
                    }
                }

                foreach ($faces as $face) {
                    try {
                        $faceID = $face['faceID'] ?? null;
                        $age = $face['age'] ?? null;
                        $gender = $this->mapGender($face['gender'] ?? null);
                        $emotion = $this->mapEmotion($face['emotion'] ?? null);
                        $glasses = $this->mapGlasses($face['glasses'] ?? null);

                        $dwell = (int) round(($face['dwellTime'] ?? 0));
                        $att = (int) round(($face['attentionTime'] ?? 0));
                        $totals['dwellTime']['sum'] += $dwell;
                        $totals['attentionTime']['sum'] += $att;

                        if ($gender === 'male') $totals['gender']['male'] += 1; elseif ($gender === 'female') $totals['gender']['female'] += 1;
                        if ($emotion) $totals['emotion'][$emotion] = ($totals['emotion'][$emotion] ?? 0) + 1;
                        if ($glasses === 'with') $totals['glasses']['with'] += 1; elseif ($glasses === 'without') $totals['glasses']['without'] += 1;

                        if ($faceID !== null && ($att > 0 || $dwell > 0)) {
                            $impressionSet[$faceID] = true;
                        }

                        // per-bucket accumulation
                        $b = &$buckets[$bucketKey];
                        $b['faces'] = ($b['faces'] ?? 0) + 1;
                        if ($gender === 'male') $b['gender']['male'] = ($b['gender']['male'] ?? 0) + 1; elseif ($gender === 'female') $b['gender']['female'] = ($b['gender']['female'] ?? 0) + 1;
                        if ($emotion) $b['emotion'][$emotion] = ($b['emotion'][$emotion] ?? 0) + 1;
                        if ($glasses === 'with') $b['glasses']['with'] = ($b['glasses']['with'] ?? 0) + 1; elseif ($glasses === 'without') $b['glasses']['without'] = ($b['glasses']['without'] ?? 0) + 1;
                        $b['dwellTime']['sum'] = ($b['dwellTime']['sum'] ?? 0) + $dwell;
                        $b['attentionTime']['sum'] = ($b['attentionTime']['sum'] ?? 0) + $att;
                        $b['ageBins'] = $b['ageBins'] ?? ['<20' => 0, '20-29' => 0, '30-45' => 0, '45+' => 0];
                        $b['ageBins'][$this->ageBin($age)] += 1;
                    } catch (\Exception $e) {
                        Log::warning('Error processing face in aggregation', [
                            'faceID' => $face['faceID'] ?? null,
                            'timestamp' => $frame['timestamp'] ?? null,
                            'error' => $e->getMessage()
                        ]);
                        continue;
                    }
                }
            } catch (\Exception $e) {
                Log::warning('Error processing frame in aggregation', [
                    'timestamp' => $frame['timestamp'] ?? null,
                    'playerUUID' => $frame['playerUUID'] ?? null,
                    'error' => $e->getMessage()
                ]);
                continue;
            }
        }

        $totals['impressions'] = count($impressionSet);
        $faceCount = max(1, $totals['faces']);
        $totals['dwellTime']['avg'] = $totals['dwellTime']['sum'] / $faceCount;
        $totals['attentionTime']['avg'] = $totals['attentionTime']['sum'] / $faceCount;

        // compute percentages in totals
        $genderSum = max(1, $totals['gender']['male'] + $totals['gender']['female']);
        $emotionSum = array_sum($totals['emotion']);
        $glassesSum = max(1, $totals['glasses']['with'] + $totals['glasses']['without']);

        $percent = fn($count, $sum) => $sum ? ($count / $sum) : 0;

        $totals['genderPct'] = [
            'male' => $percent($totals['gender']['male'], $genderSum),
            'female' => $percent($totals['gender']['female'], $genderSum),
        ];
        $totals['emotionPct'] = [];
        foreach ($totals['emotion'] as $k => $v) {
            $totals['emotionPct'][$k] = $percent($v, max(1, $emotionSum));
        }
        $totals['glassesPct'] = [
            'with' => $percent($totals['glasses']['with'], $glassesSum),
            'without' => $percent($totals['glasses']['without'], $glassesSum),
        ];

        // format buckets to array
        $bucketList = [];
        foreach ($buckets as $key => $b) {
            $facesB = $b['faces'] ?? 0;
            $f = max(1, $facesB);
            $bucketList[] = [
                'bucket' => $key,
                'faces' => $facesB,
                'gender' => $b['gender'] ?? ['male' => 0, 'female' => 0],
                'emotion' => $b['emotion'] ?? ['Happy' => 0, 'Satisfied' => 0, 'Neutral' => 0, 'Unhappy' => 0],
                'glasses' => $b['glasses'] ?? ['with' => 0, 'without' => 0],
                'dwellTime' => [
                    'sum' => $b['dwellTime']['sum'] ?? 0,
                    'avg' => ($b['dwellTime']['sum'] ?? 0) / $f,
                ],
                'attentionTime' => [
                    'sum' => $b['attentionTime']['sum'] ?? 0,
                    'avg' => ($b['attentionTime']['sum'] ?? 0) / $f,
                ],
                'ageBins' => $b['ageBins'] ?? ['<20' => 0, '20-29' => 0, '30-45' => 0, '45+' => 0],
            ];
        }

        $result = [
            'bucketType' => $bucket,
            'totals' => $totals,
            'buckets' => $bucketList,
        ];
        
        // Cache the result (if TTL > 0)
        if ($cacheTtl > 0) {
            Cache::put($cacheKey, $result, $cacheTtl);
        }
        
        $duration = round((microtime(true) - $startTime) * 1000, 2);
        Log::debug('Aggregation completed', [
            'frameCount' => $frameCount,
            'bucketType' => $bucket,
            'durationMs' => $duration,
            'cached' => true
        ]);
        
        return $result;
    }
    
    /**
     * Generate cache key based on aggregation parameters
     */
    private function generateCacheKey(array $frames, string $start, string $end, ?string $bucketType): string
    {
        // Create a hash of frame data to detect changes
        $frameHash = md5(json_encode(array_map(function($frame) {
            return [
                'timestamp' => $frame['timestamp'] ?? 0,
                'playerUUID' => $frame['playerUUID'] ?? null,
                'faceCount' => count($frame['faceDetections'] ?? [])
            ];
        }, $frames)));
        
        return sprintf(
            'aggregation:%s:%s:%s:%s',
            $frameHash,
            $start,
            $end,
            $bucketType ?? 'auto'
        );
    }

    /**
     * Automatically select bucket type based on time range
     */
    private function autoBucket(int $diffSec): string
    {
        if ($diffSec <= 86400) return 'hourly8';
        if ($diffSec <= 86400 * 7) return 'day';
        if ($diffSec <= 86400 * 31) return 'week';
        if ($diffSec <= 86400 * 365) return 'month';
        return 'year';
    }

    /**
     * Create empty buckets for the time range
     *
     * @return array<string, array<string, mixed>>
     */
    private function makeBuckets(int $start, int $end, string $type): array
    {
        $buckets = [];
        if ($type === 'hourly8') {
            $seg = ($end - $start) / 8;
            for ($i = 0; $i < 8; $i++) {
                $k = date('c', (int) ($start + $i * $seg));
                $buckets[$k] = $this->emptyBucket();
            }
            return $buckets;
        }
        $cur = $start;
        while ($cur < $end) {
            $k = match ($type) {
                'day' => date('Y-m-d', $cur),
                'week' => date('o-\WW', $cur),
                'month' => date('Y-m', $cur),
                'year' => date('Y', $cur),
                default => date('c', $cur)
            };
            $buckets[$k] = $this->emptyBucket();
            $cur = match ($type) {
                'day' => strtotime('+1 day', $cur),
                'week' => strtotime('+1 week', $cur),
                'month' => strtotime('+1 month', $cur),
                'year' => strtotime('+1 year', $cur),
                default => $end
            };
        }
        return $buckets;
    }

    /**
     * Get bucket key for a timestamp
     * For hourly8, returns the bucket start time for the segment containing $ts
     */
    private function bucketKeyFor(int $ts, string $type, int $startTs = 0, int $endTs = 0): string
    {
        if ($type === 'hourly8' && $startTs > 0 && $endTs > 0) {
            // Find which of the 8 segments this timestamp belongs to
            $seg = ($endTs - $startTs) / 8;
            $index = (int) floor(($ts - $startTs) / $seg);
            $index = max(0, min(7, $index)); // Clamp to 0-7
            $bucketStart = (int) ($startTs + $index * $seg);
            return date('c', $bucketStart);
        }
        
        return match ($type) {
            'hourly8' => date('c', $ts),
            'day' => date('Y-m-d', $ts),
            'week' => date('o-\WW', $ts),
            'month' => date('Y-m', $ts),
            'year' => date('Y', $ts),
            default => date('c', $ts)
        };
    }

    /**
     * Create empty bucket structure
     *
     * @return array<string, mixed>
     */
    private function emptyBucket(): array
    {
        return [
            'faces' => 0,
            'gender' => ['male' => 0, 'female' => 0],
            'emotion' => ['Happy' => 0, 'Satisfied' => 0, 'Neutral' => 0, 'Unhappy' => 0],
            'glasses' => ['with' => 0, 'without' => 0],
            'dwellTime' => ['sum' => 0],
            'attentionTime' => ['sum' => 0],
            'ageBins' => ['<20' => 0, '20-29' => 0, '30-45' => 0, '45+' => 0],
        ];
    }

    /**
     * Map age to age bin
     */
    private function ageBin($age): string
    {
        if (!is_numeric($age)) return '20-29';
        $a = (int) $age;
        if ($a < 20) return '<20';
        if ($a < 30) return '20-29';
        if ($a <= 45) return '30-45';
        return '45+';
    }

    /**
     * Map gender value to string
     */
    private function mapGender($g): ?string
    {
        if ($g === 0 || $g === '0' || $g === 'male') return 'male';
        if ($g === 1 || $g === '1' || $g === 'female') return 'female';
        return null;
    }

    /**
     * Map emotion value to string
     */
    private function mapEmotion($e): ?string
    {
        $map = [0 => 'Happy', 1 => 'Satisfied', 2 => 'Neutral', 3 => 'Unhappy'];
        if (isset($map[$e])) return $map[$e];
        if (is_string($e)) return $map[(int) $e] ?? null;
        return null;
    }

    /**
     * Map glasses value to string
     */
    private function mapGlasses($g): ?string
    {
        if ($g === true || $g === 1 || $g === '1' || $g === 'glasses') return 'with';
        if ($g === false || $g === 0 || $g === '0' || $g === 'no_glasses') return 'without';
        return null;
    }
}
