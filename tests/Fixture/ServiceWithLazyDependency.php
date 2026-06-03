<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Tests\Fixture;

final readonly class ServiceWithLazyDependency
{
    public function __construct(
        public LazyTrackedService $service,
    ) {
    }
}
