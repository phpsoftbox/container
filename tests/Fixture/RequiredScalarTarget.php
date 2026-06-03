<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Tests\Fixture;

final readonly class RequiredScalarTarget
{
    public function __construct(
        public string $name,
    ) {
    }
}
