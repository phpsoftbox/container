<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Tests\Fixture;

final readonly class CircularA
{
    public function __construct(
        public CircularB $dependency,
    ) {
    }
}
