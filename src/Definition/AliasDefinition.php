<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Definition;

use PhpSoftBox\Container\Container;

final readonly class AliasDefinition implements DefinitionInterface
{
    public function __construct(
        private string $targetId,
    ) {
    }

    public function targetId(): string
    {
        return $this->targetId;
    }

    public function resolve(Container $container, string $id, array $parameters = [], bool $fresh = false): mixed
    {
        return $fresh
            ? $container->make($this->targetId, $parameters)
            : $container->get($this->targetId);
    }
}
