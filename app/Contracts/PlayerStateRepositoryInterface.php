<?php

namespace App\Contracts;

interface PlayerStateRepositoryInterface
{
    public function setState(string $playerId, array $state): void;
    public function getState(string $playerId): ?array;
    public function bindCamera(string $cameraId, string $playerId): void;
    public function resolvePlayerByCamera(?string $cameraId, ?string $playerUUID): ?string;
}
