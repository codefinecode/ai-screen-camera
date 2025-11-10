#!/usr/bin/env php
<?php

if ($argc < 2) {
    echo "Usage: php sim-frames.php <url> [count]\n";
    echo "Example: php sim-frames.php http://localhost:8080/api/v1/frames 5\n";
    exit(1);
}

$url = $argv[1];
$count = (int) ($argv[2] ?? 1);
$base = [
    'error' => 0,
    'timestamp' => time(),
    'playerUUID' => 'screen-1',
    'imgDataBase64' => '',
    'imgWidth' => 1920,
    'imgHeight' => 1080,
    'faceDetections' => [
        ['faceID' => 101, 'age' => 29, 'gender' => 1, 'dwellTime' => 4000, 'attentionTime' => 0, 'emotion' => 0, 'glasses' => 0]
    ]
];

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($base),
]);

for ($i = 0; $i < $count; $i++) {
    $base['timestamp'] = time() + $i;
    $base['faceDetections'][0]['faceID'] = 101 + $i;
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($base));
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    echo "Frame $i: HTTP $code - $res\n";
    usleep(100000);
}
curl_close($ch);

