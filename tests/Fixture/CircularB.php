<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Tests\Fixture;

final readonly class CircularB
{
    public function __construct(
        public CircularA $dependency,
    ) {
    }
}
