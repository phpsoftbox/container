<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Factory;

final readonly class RequestedEntry
{
    public function __construct(
        private string $name,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }
}
