<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Definition;

use PhpSoftBox\Container\Container;
use PhpSoftBox\Env\EnvStorage;

use function array_key_exists;
use function class_exists;
use function getenv;

final readonly class EnvDefinition implements DefinitionInterface
{
    public function __construct(
        private string $name,
        private mixed $default = null,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function default(): mixed
    {
        return $this->default;
    }

    public function resolve(Container $container, string $id, array $parameters = [], bool $fresh = false): mixed
    {
        // Опциональная интеграция с phpsoftbox/env: значения из .env хранятся в EnvStorage и не обязательно
        // экспортируются в $_ENV/$_SERVER/getenv(). Жёсткой зависимости нет — класс проверяется в runtime.
        if (class_exists(EnvStorage::class) && EnvStorage::has($this->name)) {
            return EnvStorage::get($this->name);
        }

        if (array_key_exists($this->name, $_ENV)) {
            return $_ENV[$this->name];
        }

        if (array_key_exists($this->name, $_SERVER)) {
            return $_SERVER[$this->name];
        }

        $value = getenv($this->name);
        if ($value !== false) {
            return $value;
        }

        return $container->resolveInlineValue($this->default);
    }
}
