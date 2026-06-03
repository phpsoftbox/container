<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Definition\Helper;

use PhpSoftBox\Container\Definition\DefinitionInterface;

interface DefinitionHelperInterface
{
    public function toDefinition(string $id): DefinitionInterface;
}
