<?php

declare(strict_types=1);

namespace PhpSoftBox\Container;

interface InvokerInterface
{
    /**
     * @param array<string|int, mixed> $parameters
     */
    public function call(mixed $callable, array $parameters = []): mixed;
}
