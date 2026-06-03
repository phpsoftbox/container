<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Definition\Helper;

use PhpSoftBox\Container\Definition\DefinitionInterface;
use PhpSoftBox\Container\Definition\FactoryDefinition;

class FactoryDefinitionHelper implements DefinitionHelperInterface
{
    public function __construct(
        private readonly mixed $factory,
    ) {
    }

    public function toDefinition(string $id): DefinitionInterface
    {
        return new FactoryDefinition($this->factory);
    }
}
