<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Tests\Fixture;

use PhpSoftBox\Container\Attribute\Injectable;

#[Injectable(lazy: true)]
final class InjectableLazyService
{
    public static int $instances = 0;
    public readonly int $instanceId;

    public function __construct()
    {
        self::$instances++;
        $this->instanceId = self::$instances;
    }

    public function marker(): string
    {
        return 'injectable-lazy-' . $this->instanceId;
    }
}
