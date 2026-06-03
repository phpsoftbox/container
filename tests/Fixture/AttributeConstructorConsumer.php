<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Tests\Fixture;

use PhpSoftBox\Container\Attribute\Inject;

final readonly class AttributeConstructorConsumer
{
    public function __construct(
        #[Inject('custom.attribute.dep')]
        public AttributeDependency $dependency,
    ) {
    }
}
