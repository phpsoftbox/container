<?php

declare(strict_types=1);

namespace PhpSoftBox\Container;

use PhpSoftBox\Container\Definition\AddDefinition;
use PhpSoftBox\Container\Definition\Helper\AddDefinitionHelper;
use PhpSoftBox\Container\Definition\Helper\AutowireDefinitionHelper;
use PhpSoftBox\Container\Definition\Helper\CreateDefinitionHelper;
use PhpSoftBox\Container\Definition\Helper\DecorateDefinitionHelper;
use PhpSoftBox\Container\Definition\Helper\EnvDefinitionHelper;
use PhpSoftBox\Container\Definition\Helper\FactoryDefinitionHelper;
use PhpSoftBox\Container\Definition\Helper\GetDefinitionHelper;
use PhpSoftBox\Container\Definition\Helper\LazyEntryHelper;
use PhpSoftBox\Container\Definition\Helper\StringDefinitionHelper;
use PhpSoftBox\Container\Definition\Helper\ValueDefinitionHelper;

function autowire(?string $className = null): AutowireDefinitionHelper
{
    return new AutowireDefinitionHelper($className);
}

/**
 * @param array<int|string, mixed> $items
 */
function add(array $items, string $strategy = AddDefinition::MERGE_SHALLOW): AddDefinitionHelper
{
    return new AddDefinitionHelper($items, $strategy);
}

function create(?string $className = null): CreateDefinitionHelper
{
    return new CreateDefinitionHelper($className);
}

function factory(mixed $factory): FactoryDefinitionHelper
{
    return new FactoryDefinitionHelper($factory);
}

function decorate(mixed $decorator): DecorateDefinitionHelper
{
    return new DecorateDefinitionHelper($decorator);
}

function env(string $name, mixed $default = null): EnvDefinitionHelper
{
    return new EnvDefinitionHelper($name, $default);
}

function lazy(?string $id = null, ?string $className = null): LazyEntryHelper
{
    return new LazyEntryHelper($id, $className);
}

function string(string $value): StringDefinitionHelper
{
    return new StringDefinitionHelper($value);
}

function get(string $id): GetDefinitionHelper
{
    return new GetDefinitionHelper($id);
}

function value(mixed $value): ValueDefinitionHelper
{
    return new ValueDefinitionHelper($value);
}
