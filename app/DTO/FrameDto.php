<?php

namespace App\DTO;

class FrameDto
{
    public function __construct(
        public int $timestamp,
        public ?string $playerUUID,
        public ?string $cameraId,
        public ?int $imgWidth,
        public ?int $imgHeight,
        /** @var array<int, array<string, mixed>> */
        public array $faceDetections,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['timestamp'],
            $data['playerUUID'] ?? null,
            $data['cameraId'] ?? null,
            isset($data['imgWidth']) ? (int) $data['imgWidth'] : null,
            isset($data['imgHeight']) ? (int) $data['imgHeight'] : null,
            array_values($data['faceDetections'] ?? [])
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'timestamp' => $this->timestamp,
            'playerUUID' => $this->playerUUID,
            'cameraId' => $this->cameraId,
            'imgWidth' => $this->imgWidth,
            'imgHeight' => $this->imgHeight,
            'faceDetections' => $this->faceDetections,
        ];
    }
}
