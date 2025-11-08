<?php

namespace App\Contracts;

interface AwsFramesReaderInterface
{
    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function fetchFrames(array $filters): array;
}
