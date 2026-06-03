<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Definition\Helper;

use PhpSoftBox\Container\Definition\DecoratorDefinition;

final readonly class DecorateDefinitionHelper implements DecoratorHelperInterface
{
    public function __construct(
        private mixed $decorator,
    ) {
    }

    public function toDecorator(string $id): DecoratorDefinition
    {
        return new DecoratorDefinition($this->decorator);
    }
}
