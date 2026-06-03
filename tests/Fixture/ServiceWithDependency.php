<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Tests\Fixture;

final readonly class ServiceWithDependency
{
    public function __construct(
        public SimpleDependency $dependency,
        public ?string $label = null,
    ) {
    }
}
