<?php

/**
 * Simple verification script for TriggerEngine logic
 * This tests the core matching logic without requiring full Laravel setup
 */

// Simulate the matchesTrigger logic
function matchesTrigger(array $face, array $trigger): bool
{
    // Age range check
    if (isset($trigger['age']) && is_array($trigger['age']) && count($trigger['age']) === 2) {
        $age = $face['age'] ?? null;
        if ($age === null || $age < $trigger['age'][0] || $age > $trigger['age'][1]) {
            return false;
        }
        
        if (isset($trigger['ageConfidence'])) {
            $conf = (float) ($face['ageConfidence'] ?? 0);
            if ($conf < (float) $trigger['ageConfidence']) {
                return false;
            }
        }
    }

    // Gender check
    if (isset($trigger['gender'])) {
        $expectedGender = $trigger['gender'] === 'male' ? 0 : 1;
        $faceGender = $face['gender'] ?? null;
        
        if ($faceGender !== $expectedGender) {
            return false;
        }
        
        if (isset($trigger['genderConfidence'])) {
            $conf = (float) ($face['genderConfidence'] ?? 0);
            if ($conf < (float) $trigger['genderConfidence']) {
                return false;
            }
        }
    }

    // Emotion check
    if (isset($trigger['emotion']) && is_array($trigger['emotion'])) {
        $emotion = $face['emotion'] ?? null;
        if (!in_array($emotion, $trigger['emotion'], true)) {
            return false;
        }
        
        if (isset($trigger['emotionConfidence'])) {
            $conf = (float) ($face['emotionConfidence'] ?? 0);
            if ($conf < (float) $trigger['emotionConfidence']) {
                return false;
            }
        }
    }

    // DwellTime check
    if (isset($trigger['dwellTime'])) {
        $dwell = (int) ($face['dwellTime'] ?? 0);
        if ($dwell < (int) $trigger['dwellTime']) {
            return false;
        }
    }

    // AttentionTime check
    if (isset($trigger['attentionTime'])) {
        $att = (int) ($face['attentionTime'] ?? 0);
        if ($att < (int) $trigger['attentionTime']) {
            return false;
        }
    }

    // Glasses check
    if (isset($trigger['glasses'])) {
        $expectedGlasses = $trigger['glasses'] === 'glasses' ? 1 : 0;
        $faceGlasses = $face['glasses'] ?? null;
        
        if ($faceGlasses !== $expectedGlasses) {
            return false;
        }
        
        if (isset($trigger['glassesConfidence'])) {
            $conf = (float) ($face['glassesConfidence'] ?? 0);
            if ($conf < (float) $trigger['glassesConfidence']) {
                return false;
            }
        }
    }

    // FirstSeen check
    if (isset($trigger['firstSeen']) && $trigger['firstSeen'] === true) {
        $isLastTimeSeen = (int) ($face['isLastTimeSeen'] ?? 0);
        if ($isLastTimeSeen !== 0) {
            return false;
        }
    }

    return true;
}

// Test cases
$tests = [
    [
        'name' => 'Age range match',
        'face' => ['faceID' => 1, 'age' => 30, 'ageConfidence' => 0.9],
        'trigger' => ['id' => 'trigger-1', 'age' => [25, 35], 'ageConfidence' => 0.8],
        'expected' => true
    ],
    [
        'name' => 'Age out of range',
        'face' => ['faceID' => 1, 'age' => 45],
        'trigger' => ['id' => 'trigger-1', 'age' => [25, 35]],
        'expected' => false
    ],
    [
        'name' => 'Age confidence too low',
        'face' => ['faceID' => 1, 'age' => 30, 'ageConfidence' => 0.5],
        'trigger' => ['id' => 'trigger-1', 'age' => [25, 35], 'ageConfidence' => 0.8],
        'expected' => false
    ],
    [
        'name' => 'Gender match (female)',
        'face' => ['faceID' => 1, 'gender' => 1, 'genderConfidence' => 0.9],
        'trigger' => ['id' => 'trigger-1', 'gender' => 'female', 'genderConfidence' => 0.8],
        'expected' => true
    ],
    [
        'name' => 'Gender mismatch',
        'face' => ['faceID' => 1, 'gender' => 0],
        'trigger' => ['id' => 'trigger-1', 'gender' => 'female'],
        'expected' => false
    ],
    [
        'name' => 'Emotion match (multiple)',
        'face' => ['faceID' => 1, 'emotion' => 1, 'emotionConfidence' => 0.9],
        'trigger' => ['id' => 'trigger-1', 'emotion' => [0, 1], 'emotionConfidence' => 0.8],
        'expected' => true
    ],
    [
        'name' => 'Emotion not in list',
        'face' => ['faceID' => 1, 'emotion' => 3],
        'trigger' => ['id' => 'trigger-1', 'emotion' => [0, 1]],
        'expected' => false
    ],
    [
        'name' => 'DwellTime sufficient',
        'face' => ['faceID' => 1, 'dwellTime' => 2000],
        'trigger' => ['id' => 'trigger-1', 'dwellTime' => 1000],
        'expected' => true
    ],
    [
        'name' => 'DwellTime insufficient',
        'face' => ['faceID' => 1, 'dwellTime' => 500],
        'trigger' => ['id' => 'trigger-1', 'dwellTime' => 1000],
        'expected' => false
    ],
    [
        'name' => 'AttentionTime sufficient',
        'face' => ['faceID' => 1, 'attentionTime' => 1500],
        'trigger' => ['id' => 'trigger-1', 'attentionTime' => 1000],
        'expected' => true
    ],
    [
        'name' => 'Glasses match',
        'face' => ['faceID' => 1, 'glasses' => 1, 'glassesConfidence' => 0.9],
        'trigger' => ['id' => 'trigger-1', 'glasses' => 'glasses', 'glassesConfidence' => 0.8],
        'expected' => true
    ],
    [
        'name' => 'FirstSeen match',
        'face' => ['faceID' => 1, 'isLastTimeSeen' => 0],
        'trigger' => ['id' => 'trigger-1', 'firstSeen' => true],
        'expected' => true
    ],
    [
        'name' => 'FirstSeen not match (seen before)',
        'face' => ['faceID' => 1, 'isLastTimeSeen' => 1],
        'trigger' => ['id' => 'trigger-1', 'firstSeen' => true],
        'expected' => false
    ],
    [
        'name' => 'Complex trigger (all conditions)',
        'face' => [
            'faceID' => 1,
            'age' => 30,
            'ageConfidence' => 0.9,
            'gender' => 1,
            'genderConfidence' => 0.9,
            'emotion' => 0,
            'emotionConfidence' => 0.9,
            'dwellTime' => 2000,
            'attentionTime' => 1500,
            'glasses' => 1,
            'glassesConfidence' => 0.9
        ],
        'trigger' => [
            'id' => 'trigger-complex',
            'age' => [25, 35],
            'ageConfidence' => 0.8,
            'gender' => 'female',
            'genderConfidence' => 0.8,
            'emotion' => [0, 1],
            'emotionConfidence' => 0.8,
            'dwellTime' => 1000,
            'attentionTime' => 1000,
            'glasses' => 'glasses',
            'glassesConfidence' => 0.8
        ],
        'expected' => true
    ],
];

// Run tests
$passed = 0;
$failed = 0;

echo "Running TriggerEngine Logic Tests\n";
echo str_repeat("=", 50) . "\n\n";

foreach ($tests as $test) {
    $result = matchesTrigger($test['face'], $test['trigger']);
    $success = $result === $test['expected'];
    
    if ($success) {
        $passed++;
        echo "✓ PASS: {$test['name']}\n";
    } else {
        $failed++;
        echo "✗ FAIL: {$test['name']}\n";
        echo "  Expected: " . ($test['expected'] ? 'true' : 'false') . "\n";
        echo "  Got: " . ($result ? 'true' : 'false') . "\n";
    }
}

echo "\n" . str_repeat("=", 50) . "\n";
echo "Results: {$passed} passed, {$failed} failed\n";

exit($failed > 0 ? 1 : 0);
