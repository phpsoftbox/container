<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Tests\Fixture;

use PhpSoftBox\Container\Attribute\Inject;

final class AttributeNamedPropertyConsumer
{
    #[Inject('custom.attribute.dep')]
    public AttributeDependency $dependency;
}
