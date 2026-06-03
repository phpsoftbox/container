<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Tests\Fixture;

use PhpSoftBox\Container\Attribute\Inject;

final class AttributeMethodConsumer
{
    public ?AttributeDependency $dependency = null;

    #[Inject]
    public function setDependency(AttributeDependency $dependency): void
    {
        $this->dependency = $dependency;
    }
}
