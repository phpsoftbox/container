<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Definition\Helper;

use PhpSoftBox\Container\Definition\DefinitionInterface;
use PhpSoftBox\Container\Definition\ValueDefinition;

class ValueDefinitionHelper implements DefinitionHelperInterface
{
    public function __construct(
        private readonly mixed $value,
    ) {
    }

    public function toDefinition(string $id): DefinitionInterface
    {
        return new ValueDefinition($this->value);
    }
}
