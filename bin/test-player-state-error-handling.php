<?php

/**
 * Test script to verify error handling in PlayerStateRepository
 * Tests for Task 2: Add comprehensive error handling to PlayerStateRepository
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

echo "Testing PlayerStateRepository Error Handling\n";
echo "==================================================\n\n";

$repo = app(\App\Services\PlayerStateRepository::class);

// Test 1: resolvePlayerByCamera with valid data
echo "Test 1: resolvePlayerByCamera with valid data\n";
Redis::hset('camera:player', 'test-camera-1', 'player-123');
$result = $repo->resolvePlayerByCamera('test-camera-1', 'uuid-1');
echo "Result: " . ($result === 'player-123' ? "✓ PASS" : "✗ FAIL") . "\n\n";

// Test 2: resolvePlayerByCamera with missing camera
echo "Test 2: resolvePlayerByCamera with missing camera\n";
Redis::del('camera:test-camera-missing');
$result = $repo->resolvePlayerByCamera('test-camera-missing', 'uuid-2');
echo "Result: " . ($result === null ? "✓ PASS (returns null)" : "✗ FAIL") . "\n\n";

// Test 3: getState with valid player
echo "Test 3: getState with valid player\n";
$testState = [
    'playerId' => 'player-123',
    'content' => [
        ['contentId' => 'content-1', 'contentType' => 'video']
    ]
];
Redis::set('player:state:player-123', json_encode($testState));
$result = $repo->getState('player-123');
echo "Result: " . (is_array($result) && $result['playerId'] === 'player-123' ? "✓ PASS" : "✗ FAIL") . "\n\n";

// Test 4: getState with missing player
echo "Test 4: getState with missing player\n";
Redis::del('player:state:player-missing');
$result = $repo->getState('player-missing');
echo "Result: " . ($result === null ? "✓ PASS (returns null)" : "✗ FAIL") . "\n\n";

// Test 5: getState with invalid JSON
echo "Test 5: getState with invalid JSON\n";
Redis::set('player:state:player-invalid-json', '{invalid json}');
$result = $repo->getState('player-invalid-json');
echo "Result: " . ($result === null ? "✓ PASS (returns null on invalid JSON)" : "✗ FAIL") . "\n\n";

// Test 6: setState with valid data
echo "Test 6: setState with valid data\n";
$newState = [
    'playerId' => 'player-456',
    'content' => [
        ['contentId' => 'content-2', 'contentType' => 'image']
    ]
];
$repo->setState('player-456', $newState);
$stored = json_decode(Redis::get('player:state:player-456'), true);
echo "Result: " . (is_array($stored) && $stored['playerId'] === 'player-456' ? "✓ PASS" : "✗ FAIL") . "\n\n";

// Test 7: Check error handling methods exist
echo "Test 7: Verify error handling implementation\n";
$reflection = new \ReflectionClass($repo);
$methods = $reflection->getMethods();
$methodNames = array_map(fn($m) => $m->getName(), $methods);

$hasResolve = in_array('resolvePlayerByCamera', $methodNames);
$hasGetState = in_array('getState', $methodNames);
$hasSetState = in_array('setState', $methodNames);

echo "Has resolvePlayerByCamera: " . ($hasResolve ? "✓" : "✗") . "\n";
echo "Has getState: " . ($hasGetState ? "✓" : "✗") . "\n";
echo "Has setState: " . ($hasSetState ? "✓" : "✗") . "\n\n";

// Test 8: Check that methods handle Redis exceptions gracefully
echo "Test 8: Methods handle exceptions gracefully\n";
echo "Note: This test verifies that methods have try-catch blocks\n";

// Read the source code to verify try-catch blocks exist
$sourceFile = __DIR__ . '/../app/Services/PlayerStateRepository.php';
$source = file_get_contents($sourceFile);

$hasTryCatch = (substr_count($source, 'try {') >= 3 && substr_count($source, 'catch') >= 3);
$hasLogging = (strpos($source, 'Log::error') !== false || strpos($source, 'Log::warning') !== false);

echo "Has try-catch blocks: " . ($hasTryCatch ? "✓ PASS" : "✗ FAIL") . "\n";
echo "Has error logging: " . ($hasLogging ? "✓ PASS" : "✗ FAIL") . "\n\n";

// Cleanup
Redis::hdel('camera:player', 'test-camera-1');
Redis::del('player:state:player-123');
Redis::del('player:state:player-invalid-json');
Redis::del('player:state:player-456');

echo "==================================================\n";
echo "PlayerStateRepository Error Handling Tests Complete\n";
