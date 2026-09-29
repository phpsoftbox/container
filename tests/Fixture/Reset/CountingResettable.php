<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Tests\Fixture\Reset;

use PhpSoftBox\Container\Reset\ResetInterface;

final class CountingResettable implements ResetInterface
{
    public int $resets = 0;

    public function reset(): void
    {
        $this->resets++;
    }
}
