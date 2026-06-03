<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Compilation;

use RuntimeException;

use function apcu_delete;
use function apcu_enabled;
use function apcu_exists;
use function apcu_fetch;
use function apcu_store;
use function function_exists;

final readonly class ApcuDefinitionCache implements DefinitionCacheInterface
{
    public function __construct(
        private string $prefix = 'phpsoftbox.container.',
    ) {
    }

    public function has(string $key): bool
    {
        return $this->apcuAvailable() && apcu_exists($this->cacheKey($key));
    }

    public function get(string $key): mixed
    {
        if (!$this->apcuAvailable()) {
            return null;
        }

        $success = false;
        $value   = apcu_fetch($this->cacheKey($key), $success);

        return $success ? $value : null;
    }

    public function set(string $key, mixed $value, int $ttl = 0): void
    {
        if (!$this->apcuAvailable()) {
            return;
        }

        if (!apcu_store($this->cacheKey($key), $value, $ttl)) {
            throw new RuntimeException('Failed to write APCu definition cache for key: ' . $key);
        }
    }

    public function delete(string $key): void
    {
        if (!$this->apcuAvailable()) {
            return;
        }

        apcu_delete($this->cacheKey($key));
    }

    private function cacheKey(string $key): string
    {
        return $this->prefix . $key;
    }

    private function apcuAvailable(): bool
    {
        return function_exists('apcu_enabled') && apcu_enabled();
    }
}
