<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Tests\Fixture\Reset;

use RuntimeException;

/**
 * Сервис из «чужого» пакета: сбрасывается методом из карты хуков, интерфейс не реализует.
 */
final class WarmupCache
{
    public int $clears = 0;

    public function __construct(
        private readonly bool $fail = false,
    ) {
    }

    public function clearWarmup(): void
    {
        $this->clears++;

        if ($this->fail) {
            throw new RuntimeException('Warmup reset failed.');
        }
    }
}
