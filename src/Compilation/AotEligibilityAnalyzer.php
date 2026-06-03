<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Compilation;

use PhpSoftBox\Container\Attribute\Inject;
use PhpSoftBox\Container\Definition\AliasDefinition;
use PhpSoftBox\Container\Definition\DefinitionInterface;
use PhpSoftBox\Container\Definition\FactoryDefinition;
use PhpSoftBox\Container\Definition\Helper\DefinitionHelperInterface;
use PhpSoftBox\Container\Definition\ObjectDefinition;
use PhpSoftBox\Container\Definition\StringDefinition;
use PhpSoftBox\Container\Definition\ValueDefinition;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;

use function array_key_exists;
use function class_exists;
use function is_array;
use function is_bool;
use function is_callable;
use function is_float;
use function is_int;
use function is_null;
use function is_string;
use function str_contains;

final readonly class AotEligibilityAnalyzer
{
    /**
     * @param array<string, mixed> $definitions
     * @param array<string, list<mixed>> $decorators
     * @param array<string, string|null> $lazyEntries
     */
    public function __construct(
        private array $definitions,
        private array $decorators,
        private array $lazyEntries,
        private bool $autowiring,
        private bool $attributes,
    ) {
    }

    /**
     * @return array{
     *     id: string,
     *     eligible: bool,
     *     kind: string|null,
     *     reasons: list<string>,
     * }
     */
    public function analyze(string $id, ?DefinitionInterface $definition = null): array
    {
        $reasons   = [];
        $decorated = $this->isDecorated($id);

        if (str_contains($id, '*')) {
            $reasons[] = 'wildcard-definition';
        }

        if (array_key_exists($id, $this->lazyEntries)) {
            $reasons[] = 'lazy-entry';
        }

        if ($definition === null && array_key_exists($id, $this->definitions)) {
            $definition = $this->normalizeDefinition($id, $this->definitions[$id]);
        }

        if ($definition === null) {
            $reasons[] = 'not-explicit-definition';

            return $this->report($id, null, $reasons);
        }

        if ($definition instanceof ValueDefinition) {
            if (!$this->isExportableValue($definition->value())) {
                $reasons[] = 'value-not-exportable';
            }

            return $this->report($id, $this->kind('value', $decorated), $reasons);
        }

        if ($definition instanceof StringDefinition) {
            return $this->report($id, $this->kind('string', $decorated), $reasons);
        }

        if ($definition instanceof ObjectDefinition) {
            return $this->report(
                $id,
                $this->kind('object', $decorated),
                [...$reasons, ...$this->objectReasons($id, $definition)],
            );
        }

        if ($definition instanceof FactoryDefinition) {
            return $this->report($id, $this->kind('factory', $decorated), $reasons);
        }

        if ($definition instanceof AliasDefinition) {
            $reasons[] = 'alias-definition';

            return $this->report($id, 'alias', $reasons);
        }

        $reasons[] = 'unsupported-definition';

        return $this->report($id, $definition::class, $reasons);
    }

    private function isDecorated(string $id): bool
    {
        return array_key_exists($id, $this->decorators) && $this->decorators[$id] !== [];
    }

    private function kind(string $kind, bool $decorated): string
    {
        return $decorated ? 'decorated-' . $kind : $kind;
    }

    /**
     * @param list<string> $reasons
     *
     * @return array{
     *     id: string,
     *     eligible: bool,
     *     kind: string|null,
     *     reasons: list<string>,
     * }
     */
    private function report(string $id, ?string $kind, array $reasons): array
    {
        return [
            'id'       => $id,
            'eligible' => $reasons === [],
            'kind'     => $kind,
            'reasons'  => $reasons,
        ];
    }

    /**
     * @return list<string>
     */
    private function objectReasons(string $id, ObjectDefinition $definition): array
    {
        $reasons = [];

        $className = $definition->className($id);
        if (!class_exists($className)) {
            $reasons[] = 'class-not-found';

            return $reasons;
        }

        try {
            $reflection = new ReflectionClass($className);
        } catch (ReflectionException) {
            $reasons[] = 'class-reflection-failed';

            return $reasons;
        }

        if (!$reflection->isInstantiable()) {
            $reasons[] = 'class-not-instantiable';
        }

        if (!$this->constructorArgumentsAreCompilable($reflection, $definition->constructorParameters())) {
            $reasons[] = 'constructor-not-compilable';
        }

        foreach ($definition->methodCalls() as $call) {
            if (!$this->isExportableValue($call['parameters'] ?? [])) {
                $reasons[] = 'method-injection-not-compilable';
                break;
            }
        }

        if (!$this->isExportableValue($definition->propertyInjections())) {
            $reasons[] = 'property-injection-not-compilable';
        }

        return $reasons;
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @param array<string|int, mixed> $definitionParameters
     */
    private function constructorArgumentsAreCompilable(ReflectionClass $reflection, array $definitionParameters): bool
    {
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return true;
        }

        [$named, $positional] = $this->splitParameters($definitionParameters);

        foreach ($constructor->getParameters() as $index => $parameter) {
            if ($parameter->isVariadic()) {
                return false;
            }

            $name = $parameter->getName();
            if (array_key_exists($name, $named)) {
                if (!$this->isExportableValue($named[$name])) {
                    return false;
                }

                continue;
            }

            if (array_key_exists($index, $positional)) {
                if (!$this->isExportableValue($positional[$index])) {
                    return false;
                }

                continue;
            }

            if (!$this->parameterFallbackIsCompilable($parameter, $index)) {
                return false;
            }
        }

        return true;
    }

    private function parameterFallbackIsCompilable(ReflectionParameter $parameter, int $index): bool
    {
        if ($this->injectAttributeIsCompilable($parameter, $index)) {
            return true;
        }

        $type = $parameter->getType();
        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $className = $type->getName();
            if (array_key_exists($className, $this->definitions) || ($this->autowiring && class_exists($className))) {
                return true;
            }
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $this->isExportableValue($parameter->getDefaultValue());
        }

        return $this->parameterAllowsNull($parameter);
    }

    private function injectAttributeIsCompilable(ReflectionParameter $parameter, int $index): bool
    {
        if (!$this->attributes) {
            return false;
        }

        $attributes = $parameter->getAttributes(Inject::class, ReflectionAttribute::IS_INSTANCEOF);
        if ($attributes === []) {
            return false;
        }

        /** @var Inject $inject */
        $inject = $attributes[0]->newInstance();
        if (is_string($inject->name)) {
            return true;
        }

        if (is_array($inject->name)) {
            return array_key_exists($parameter->getName(), $inject->name)
                || array_key_exists($index, $inject->name)
                || array_key_exists(0, $inject->name);
        }

        $type = $parameter->getType();

        return $type instanceof ReflectionNamedType && !$type->isBuiltin();
    }

    private function parameterAllowsNull(ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();
        if (!$type instanceof ReflectionType) {
            return true;
        }

        return $type->allowsNull();
    }

    private function isExportableValue(mixed $value): bool
    {
        if (is_null($value) || is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
            return true;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (!$this->isExportableValue($item)) {
                    return false;
                }
            }

            return true;
        }

        if ($value instanceof DefinitionHelperInterface) {
            return $this->isExportableValue($value->toDefinition('__aot_inline__'));
        }

        if ($value instanceof AliasDefinition) {
            return true;
        }

        if ($value instanceof ValueDefinition) {
            return $this->isExportableValue($value->value());
        }

        if ($value instanceof StringDefinition) {
            return true;
        }

        return false;
    }

    private function normalizeDefinition(string $id, mixed $value): DefinitionInterface
    {
        if ($value instanceof DefinitionInterface) {
            return $value;
        }

        if ($value instanceof DefinitionHelperInterface) {
            return $value->toDefinition($id);
        }

        if (is_callable($value) && !$value instanceof ValueDefinition) {
            return new FactoryDefinition($value);
        }

        if (is_string($value) && str_contains($value, '{')) {
            return new StringDefinition($value);
        }

        return new ValueDefinition($value);
    }

    /**
     * @param array<string|int, mixed> $parameters
     *
     * @return array{array<string, mixed>, array<int, mixed>}
     */
    private function splitParameters(array $parameters): array
    {
        $named      = [];
        $positional = [];

        foreach ($parameters as $key => $value) {
            if (is_int($key)) {
                $positional[$key] = $value;
                continue;
            }

            $named[$key] = $value;
        }

        return [$named, $positional];
    }
}
