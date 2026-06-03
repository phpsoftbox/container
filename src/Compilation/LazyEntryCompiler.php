<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Compilation;

use Closure;
use PhpSoftBox\Container\Definition\AliasDefinition;
use PhpSoftBox\Container\Definition\DefinitionInterface;
use PhpSoftBox\Container\Definition\FactoryDefinition;
use PhpSoftBox\Container\Definition\Helper\DefinitionHelperInterface;
use PhpSoftBox\Container\Definition\ObjectDefinition;
use PhpSoftBox\Container\Definition\ValueDefinition;
use PhpSoftBox\Container\Exception\ContainerException;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

use function array_key_exists;
use function array_slice;
use function class_exists;
use function in_array;
use function is_array;
use function is_callable;
use function is_object;
use function is_string;
use function ksort;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function sprintf;
use function str_contains;
use function str_replace;
use function strlen;
use function substr_count;

final class LazyEntryCompiler
{
    /**
     * @param array<string, mixed> $definitions
     * @param array<string, string|null> $lazyEntries
     */
    public function __construct(
        private readonly array $definitions,
        private readonly array $lazyEntries,
    ) {
    }

    /**
     * @return array{array<string, string>, array<string, array{internal: bool, instantiable: bool}>}
     */
    public function compile(): array
    {
        if ($this->lazyEntries === []) {
            return [[], []];
        }

        $resolvedLazyEntries = [];
        $classMetadata       = [];
        $lazyEntries         = $this->lazyEntries;
        ksort($lazyEntries);

        foreach ($lazyEntries as $id => $_className) {
            $resolvedClass = $this->resolveLazyClassName($id, []);
            if ($resolvedClass === null || $resolvedClass === '') {
                throw new ContainerException(
                    sprintf(
                        'Lazy entry "%s" requires a concrete class. Configure class name in ContainerBuilder::lazy().',
                        $id,
                    ),
                );
            }

            if (!array_key_exists($resolvedClass, $classMetadata)) {
                $classMetadata[$resolvedClass] = $this->validateLazyClass($id, $resolvedClass);
            }

            $resolvedLazyEntries[$id] = $resolvedClass;
        }

        return [$resolvedLazyEntries, $classMetadata];
    }

    /**
     * @param list<string> $visited
     */
    private function resolveLazyClassName(string $id, array $visited): ?string
    {
        if (in_array($id, $visited, true)) {
            return null;
        }

        $visited[]           = $id;
        $configuredClassName = $this->lazyEntries[$id] ?? null;
        if (is_string($configuredClassName) && $configuredClassName !== '') {
            return $configuredClassName;
        }

        if (class_exists($id)) {
            return $id;
        }

        $definition = null;
        if (array_key_exists($id, $this->definitions)) {
            $definition = $this->normalizeDefinition($id, $this->definitions[$id]);
        } else {
            $definition = $this->matchWildcardDefinition($id);
        }
        if (!$definition instanceof DefinitionInterface) {
            return null;
        }

        if ($definition instanceof AliasDefinition) {
            return $this->resolveLazyClassName($definition->targetId(), $visited);
        }

        if ($definition instanceof ObjectDefinition) {
            $className = $definition->className($id);

            return class_exists($className) ? $className : null;
        }

        if ($definition instanceof FactoryDefinition) {
            return $this->resolveFactoryReturnClassName($definition->factory());
        }

        if ($definition instanceof ValueDefinition) {
            $value = $definition->value();

            return is_object($value) ? $value::class : null;
        }

        return null;
    }

    private function matchWildcardDefinition(string $id): ?DefinitionInterface
    {
        $best           = null;
        $bestWildcards  = null;
        $bestLiteralLen = null;
        $order          = 0;
        $bestOrder      = null;

        foreach ($this->definitions as $pattern => $definition) {
            if (!str_contains($pattern, '*')) {
                $order++;
                continue;
            }

            $quoted = preg_quote($pattern, '/');
            $quoted = preg_replace('/\\\\\*/', '([^\\\\\\\\]+)', $quoted);
            if ($quoted === null) {
                $order++;
                continue;
            }

            $matches = [];
            if (preg_match('/^' . $quoted . '$/', $id, $matches) !== 1) {
                $order++;
                continue;
            }

            $normalized = $this->normalizeDefinition($pattern, $definition);
            $wildcards  = substr_count($pattern, '*');
            $literalLen = strlen(str_replace('*', '', $pattern));
            if ($best !== null
                && ($wildcards > $bestWildcards
                    || ($wildcards === $bestWildcards && $literalLen < $bestLiteralLen)
                    || ($wildcards === $bestWildcards && $literalLen === $bestLiteralLen && $order > $bestOrder))
            ) {
                $order++;
                continue;
            }

            if ($normalized instanceof AliasDefinition) {
                $best           = new AliasDefinition($this->replaceWildcards($normalized->targetId(), array_slice($matches, 1)));
                $bestWildcards  = $wildcards;
                $bestLiteralLen = $literalLen;
                $bestOrder      = $order;
                $order++;

                continue;
            }

            if ($normalized instanceof ObjectDefinition) {
                $best = new ObjectDefinition(
                    className: $this->replaceWildcards($normalized->className($id), array_slice($matches, 1)),
                    constructorParameters: $normalized->constructorParameters(),
                    methodCalls: $normalized->methodCalls(),
                    propertyInjections: $normalized->propertyInjections(),
                );
                $bestWildcards  = $wildcards;
                $bestLiteralLen = $literalLen;
                $bestOrder      = $order;
                $order++;

                continue;
            }

            $best           = $normalized;
            $bestWildcards  = $wildcards;
            $bestLiteralLen = $literalLen;
            $bestOrder      = $order;
            $order++;
        }

        return $best;
    }

    /**
     * @param array<int, string> $wildcards
     */
    private function replaceWildcards(string $value, array $wildcards): string
    {
        foreach ($wildcards as $wildcard) {
            $value = preg_replace('/\*/', $wildcard, $value, 1) ?? $value;
        }

        return $value;
    }

    /**
     * @return array{internal: bool, instantiable: bool}
     */
    private function validateLazyClass(string $id, string $className): array
    {
        if (!class_exists($className)) {
            throw new ContainerException(
                sprintf('Lazy entry "%s" references unknown class "%s".', $id, $className),
            );
        }

        try {
            $reflection = new ReflectionClass($className);
        } catch (ReflectionException $exception) {
            throw new ContainerException(
                sprintf('Unable to reflect lazy class "%s" for entry "%s".', $className, $id),
                0,
                $exception,
            );
        }

        if ($reflection->isInternal()) {
            throw new ContainerException(
                sprintf('Lazy entry "%s" cannot use internal class "%s".', $id, $className),
            );
        }

        if (!$reflection->isInstantiable()) {
            throw new ContainerException(
                sprintf('Lazy entry "%s" requires instantiable class, got "%s".', $id, $className),
            );
        }

        return [
            'internal'     => false,
            'instantiable' => true,
        ];
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

        return new ValueDefinition($value);
    }

    private function resolveFactoryReturnClassName(mixed $factory): ?string
    {
        if (is_string($factory)) {
            return class_exists($factory) ? $factory : null;
        }

        if (is_object($factory) && !is_callable($factory)) {
            return null;
        }

        if (!is_callable($factory)) {
            return null;
        }

        try {
            $reflection = $this->reflectCallable($factory);
        } catch (ReflectionException) {
            return null;
        }

        $type = $reflection->getReturnType();
        if (!$type instanceof ReflectionType) {
            return null;
        }

        return $this->resolveTypeClassName($type);
    }

    private function resolveTypeClassName(ReflectionType $type): ?string
    {
        if ($type instanceof ReflectionNamedType) {
            if ($type->isBuiltin()) {
                return null;
            }

            $className = $type->getName();

            return class_exists($className) ? $className : null;
        }

        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            foreach ($type->getTypes() as $nestedType) {
                if (!$nestedType instanceof ReflectionType) {
                    continue;
                }

                $resolved = $this->resolveTypeClassName($nestedType);
                if ($resolved !== null) {
                    return $resolved;
                }
            }
        }

        return null;
    }

    private function reflectCallable(callable $callable): ReflectionFunctionAbstract
    {
        if (is_array($callable) && isset($callable[0], $callable[1])) {
            return new ReflectionMethod($callable[0], (string) $callable[1]);
        }

        if (is_object($callable) && !($callable instanceof Closure)) {
            return new ReflectionMethod($callable, '__invoke');
        }

        return new ReflectionFunction($callable);
    }
}
