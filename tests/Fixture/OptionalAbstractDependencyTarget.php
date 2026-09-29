<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Tests\Fixture;

final readonly class OptionalAbstractDependencyTarget
{
    public function __construct(
        public ?AbstractDependency $dependency = null,
    ) {
    }
}
