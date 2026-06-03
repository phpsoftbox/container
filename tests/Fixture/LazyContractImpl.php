<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Tests\Fixture;

final class LazyContractImpl implements LazyContract
{
    public static int $instances = 0;
    private readonly int $instanceId;

    public function __construct()
    {
        self::$instances++;
        $this->instanceId = self::$instances;
    }

    public function code(): string
    {
        return 'contract-' . $this->instanceId;
    }
}
