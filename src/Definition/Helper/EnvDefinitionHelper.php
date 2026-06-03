<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Definition\Helper;

use PhpSoftBox\Container\Definition\DefinitionInterface;
use PhpSoftBox\Container\Definition\EnvDefinition;

final readonly class EnvDefinitionHelper implements DefinitionHelperInterface
{
    public function __construct(
        private string $name,
        private mixed $default = null,
    ) {
    }

    public function toDefinition(string $id): DefinitionInterface
    {
        return new EnvDefinition($this->name, $this->default);
    }
}
