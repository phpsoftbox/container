<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Tests\Fixture;

final class ConfiguredInjectionTarget
{
    public ?SimpleDependency $methodDependency   = null;
    public ?SimpleDependency $propertyDependency = null;
    public ?string $label                        = null;

    public function configure(SimpleDependency $dependency, string $label): void
    {
        $this->methodDependency = $dependency;
        $this->label            = $label;
    }
}
