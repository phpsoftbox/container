<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Tests\Fixture;

final readonly class AttributeDependency
{
    public function __construct(
        public string $value = 'attribute',
    ) {
    }
}
