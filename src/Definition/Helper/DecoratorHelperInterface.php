<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Definition\Helper;

use PhpSoftBox\Container\Definition\DecoratorDefinition;

interface DecoratorHelperInterface
{
    public function toDecorator(string $id): DecoratorDefinition;
}
