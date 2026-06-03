<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Definition;

use PhpSoftBox\Container\Container;

final readonly class StringDefinition implements DefinitionInterface
{
    public function __construct(
        private string $value,
    ) {
    }

    public function value(): string
    {
        return $this->value;
    }

    public function resolve(Container $container, string $id, array $parameters = [], bool $fresh = false): mixed
    {
        return $container->interpolateString($this->value);
    }
}
