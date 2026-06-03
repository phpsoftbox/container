<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Definition\Helper;

use PhpSoftBox\Container\Definition\AliasDefinition;
use PhpSoftBox\Container\Definition\DefinitionInterface;

class GetDefinitionHelper implements DefinitionHelperInterface
{
    public function __construct(
        private readonly string $targetId,
    ) {
    }

    public function toDefinition(string $id): DefinitionInterface
    {
        return new AliasDefinition($this->targetId);
    }
}
