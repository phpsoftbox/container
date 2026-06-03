<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Definition\Helper;

use PhpSoftBox\Container\Definition\DefinitionInterface;
use PhpSoftBox\Container\Definition\ObjectDefinition;

class CreateDefinitionHelper implements DefinitionHelperInterface
{
    /**
     * @var array<string|int, mixed>
     */
    protected array $constructorParameters = [];
    /**
     * @var list<array{method: string, parameters: array<string|int, mixed>}>
     */
    protected array $methodCalls = [];
    /**
     * @var array<string, mixed>
     */
    protected array $propertyInjections = [];

    public function __construct(
        protected readonly ?string $className = null,
    ) {
    }

    public function constructor(mixed ...$parameters): static
    {
        $this->constructorParameters = $parameters;

        return $this;
    }

    public function constructorParameter(string|int $parameter, mixed $value): static
    {
        $this->constructorParameters[$parameter] = $value;

        return $this;
    }

    public function method(string $method, mixed ...$parameters): static
    {
        $this->methodCalls[] = [
            'method'     => $method,
            'parameters' => $parameters,
        ];

        return $this;
    }

    public function methodParameter(string $method, string|int $parameter, mixed $value): static
    {
        foreach ($this->methodCalls as &$call) {
            if ($call['method'] !== $method) {
                continue;
            }

            $call['parameters'][$parameter] = $value;

            return $this;
        }
        unset($call);

        $this->methodCalls[] = [
            'method'     => $method,
            'parameters' => [$parameter => $value],
        ];

        return $this;
    }

    public function property(string $property, mixed $value): static
    {
        $this->propertyInjections[$property] = $value;

        return $this;
    }

    public function toDefinition(string $id): DefinitionInterface
    {
        return new ObjectDefinition(
            $this->className,
            $this->constructorParameters,
            $this->methodCalls,
            $this->propertyInjections,
        );
    }
}
