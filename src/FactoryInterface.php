<?php

declare(strict_types=1);

namespace PhpSoftBox\Container;

interface FactoryInterface
{
    /**
     * @param array<string|int, mixed> $parameters
     */
    public function make(string $id, array $parameters = []): mixed;
}
