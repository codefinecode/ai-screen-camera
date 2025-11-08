<?php

namespace App\Contracts;

interface TriggerEngineInterface
{
    /**
     * @param array<string, mixed>|null $triggers
     * @param array<string, mixed> $frame
     * @param array<string, mixed>|null $playerState
     * @return array<int, array<string, mixed>>
     */
    public function evaluate(?array $triggers, array $frame, ?array $playerState): array;
}
