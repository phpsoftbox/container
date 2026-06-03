<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Compilation;

interface DefinitionCacheInterface
{
    public function has(string $key): bool;

    public function get(string $key): mixed;

    public function set(string $key, mixed $value, int $ttl = 0): void;

    public function delete(string $key): void;
}
