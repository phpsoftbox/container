<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Definition;

use PhpSoftBox\Container\Container;

use function array_key_exists;
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
