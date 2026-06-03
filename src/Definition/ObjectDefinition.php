<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Definition;

use PhpSoftBox\Container\Container;

final readonly class ObjectDefinition implements DefinitionInterface
{
    /**
     * @param array<string|int, mixed> $constructorParameters
     * @param list<array{method: string, parameters: array<string|int, mixed>}> $methodCalls
     * @param array<string, mixed> $propertyInjections
     */
    public function __construct(
        private ?string $className = null,
        private array $constructorParameters = [],
        private array $methodCalls = [],
        private array $propertyInjections = [],
    ) {
    }

    public function className(string $id): string
    {
        return $this->className ?? $id;
    }

    /**
     * @return array<string|int, mixed>
     */
    public function constructorParameters(): array
    {
        return $this->constructorParameters;
    }

    /**
     * @return list<array{method: string, parameters: array<string|int, mixed>}>
     */
    public function methodCalls(): array
    {
        return $this->methodCalls;
    }

    /**
     * @return array<string, mixed>
     */
    public function propertyInjections(): array
    {
        return $this->propertyInjections;
    }

    public function resolve(Container $container, string $id, array $parameters = [], bool $fresh = false): mixed
    {
        $class  = $this->className ?? $id;
        $object = $container->instantiate($class, $parameters, $this->constructorParameters);

        return $container->injectObject(
            $object,
            $this->methodCalls,
            $this->propertyInjections,
        );
    }
}
