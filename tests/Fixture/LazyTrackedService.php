<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Tests\Fixture;

final class LazyTrackedService
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
        return 'lazy-' . $this->instanceId;
    }
}
