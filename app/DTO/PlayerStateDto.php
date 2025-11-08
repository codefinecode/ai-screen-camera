<?php

namespace App\DTO;

class PlayerStateDto
{
    public function __construct(
        public string $playerId,
        /** @var array<int, array{contentId:string, contentType:string}> */
        public array $content,
        public int $timestamp,
    ) {}

    /**
     * @param array{playerId:string, content:array<int,array{contentId:string,contentType:string}>, timestamp:int} $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['playerId'], $data['content'], (int) $data['timestamp']);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'playerId' => $this->playerId,
            'content' => $this->content,
            'timestamp' => $this->timestamp,
        ];
    }
}
