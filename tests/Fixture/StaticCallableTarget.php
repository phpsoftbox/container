<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Tests\Fixture;

final class StaticCallableTarget
{
    public static function greet(string $name): string
    {
        return 'hello ' . $name;
    }
}
