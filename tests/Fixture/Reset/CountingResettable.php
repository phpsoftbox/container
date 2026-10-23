<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Tests\Fixture\Reset;

use PhpSoftBox\Container\Reset\ResetInterface;
use RuntimeException;

final class CountingResettable implements ResetInterface
{
    public int $resets = 0;

    public function __construct(
        private readonly bool $fail = false,
    ) {
    }

    public function reset(): void
    {
        $this->resets++;

        if ($this->fail) {
            throw new RuntimeException('Service reset failed.');
        }
    }
}
