<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Definition;

use PhpSoftBox\Container\Container;

final readonly class ValueDefinition implements DefinitionInterface
{
    public function __construct(
        private mixed $value,
    ) {
    }

    public function value(): mixed
    {
        return $this->value;
    }

    public function resolve(Container $container, string $id, array $parameters = [], bool $fresh = false): mixed
    {
        return $this->value;
    }
}
