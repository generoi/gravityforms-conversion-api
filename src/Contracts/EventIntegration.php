<?php

namespace GeneroWP\GformConversionApi\Contracts;

interface EventIntegration
{
    /**
     * @param array<string,mixed> $data
     * @return null|array<string,mixed>
     */
    public function sendEvent(array $data): ?array;

    /**
     * @param array<string,mixed> $data
     */
    public function injectEvent(array $data): void;

    public function isActive(): bool;
}
