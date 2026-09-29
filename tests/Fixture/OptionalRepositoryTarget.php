<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Tests\Fixture;

final readonly class OptionalRepositoryTarget
{
    public function __construct(
        public ?UserRepositoryInterface $repository = null,
    ) {
    }
}
