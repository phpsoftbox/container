<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Definition;

use PhpSoftBox\Container\Container;

interface DefinitionInterface
{
    /**
     * @param array<string|int, mixed> $parameters
     */
    public function resolve(Container $container, string $id, array $parameters = [], bool $fresh = false): mixed;
}
