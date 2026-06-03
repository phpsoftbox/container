<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Definition\Helper;

use PhpSoftBox\Container\Definition\DefinitionInterface;
use PhpSoftBox\Container\Definition\StringDefinition;

final readonly class StringDefinitionHelper implements DefinitionHelperInterface
{
    public function __construct(
        private string $value,
    ) {
    }

    public function toDefinition(string $id): DefinitionInterface
    {
        return new StringDefinition($this->value);
    }
}
