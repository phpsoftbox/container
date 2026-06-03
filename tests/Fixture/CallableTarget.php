<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Tests\Fixture;

final class CallableTarget
{
    public function handle(string $id, SimpleDependency $dependency): string
    {
        return $id . ':' . $dependency->name;
    }
}
