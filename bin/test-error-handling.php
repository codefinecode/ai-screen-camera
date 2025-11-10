<?php

/**
 * Test script to verify error handling in services
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "Testing Error Handling Implementation\n";
echo "=====================================\n\n";

// Test 1: FrameIngestService validation error handling
echo "Test 1: FrameIngestService validation error handling\n";
$ingest = app(\App\Services\FrameIngestService::class);
$invalidFrame = ['invalid' => 'data'];
$result = $ingest->processFrame($invalidFrame);
echo "Result: " . ($result === null ? "✓ Returned null as expected" : "✗ Unexpected result") . "\n\n";

// Test 2: FrameIngestService with valid frame
echo "Test 2: FrameIngestService with valid frame\n";
$validFrame = [
    'timestamp' => time(),
    'playerUUID' => 'test-player',
    'faceDetections' => []
];
$result = $ingest->processFrame($validFrame);
echo "Result: " . ($result !== null ? "✓ Processed successfully" : "✗ Failed to process") . "\n\n";

// Test 3: ForwardFramesToAws error handling
echo "Test 3: ForwardFramesToAws job structure\n";
$job = new \App\Jobs\ForwardFramesToAws(['test' => 'payload']);
echo "Job created: ✓\n";
echo "Has failed() method: " . (method_exists($job, 'failed') ? "✓" : "✗") . "\n\n";

// Test 4: AggregationService error handling
echo "Test 4: AggregationService aggregate method\n";
$aggregationService = app(\App\Services\AggregationService::class);

try {
    // Test with invalid dates
    $aggregationService->aggregate([], 'invalid-date', '2024-01-01', null);
    echo "✗ Should have thrown exception for invalid date\n";
} catch (\InvalidArgumentException $e) {
    echo "✓ Correctly throws InvalidArgumentException for invalid dates\n";
} catch (\Exception $e) {
    echo "✗ Unexpected exception: " . $e->getMessage() . "\n";
}

try {
    // Test with start > end
    $aggregationService->aggregate([], '2024-12-31', '2024-01-01', null);
    echo "✗ Should have thrown exception for start > end\n";
} catch (\InvalidArgumentException $e) {
    echo "✓ Correctly throws InvalidArgumentException when start >= end\n";
} catch (\Exception $e) {
    echo "✗ Unexpected exception: " . $e->getMessage() . "\n";
}

try {
    // Test with too many frames
    $manyFrames = array_fill(0, 10001, ['timestamp' => time()]);
    $aggregationService->aggregate($manyFrames, '2024-01-01T00:00:00Z', '2024-01-02T00:00:00Z', null);
    echo "✗ Should have thrown exception for too many frames\n";
} catch (\RuntimeException $e) {
    echo "✓ Correctly throws RuntimeException when frame count exceeds limit\n";
} catch (\Exception $e) {
    echo "✗ Unexpected exception: " . $e->getMessage() . "\n";
}

echo "\n=====================================\n";
echo "Error Handling Tests Complete\n";
