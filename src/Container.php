<?php

declare(strict_types=1);

namespace PhpSoftBox\Container;

use Closure;
use PhpSoftBox\Container\Attribute\Inject;
use PhpSoftBox\Container\Attribute\Injectable;
use PhpSoftBox\Container\Definition\AddDefinition;
use PhpSoftBox\Container\Definition\AliasDefinition;
use PhpSoftBox\Container\Definition\DecoratorDefinition;
use PhpSoftBox\Container\Definition\DefinitionInterface;
use PhpSoftBox\Container\Definition\FactoryDefinition;
use PhpSoftBox\Container\Definition\Helper\AddDefinitionHelper;
use PhpSoftBox\Container\Definition\Helper\DecoratorHelperInterface;
use PhpSoftBox\Container\Definition\Helper\DefinitionHelperInterface;
use PhpSoftBox\Container\Definition\Helper\LazyEntryHelper;
use PhpSoftBox\Container\Definition\ObjectDefinition;
use PhpSoftBox\Container\Definition\StringDefinition;
use PhpSoftBox\Container\Definition\ValueDefinition;
use PhpSoftBox\Container\Diagnostics\ContainerDiagnostics;
use PhpSoftBox\Container\Exception\ContainerException;
use PhpSoftBox\Container\Exception\NotFoundException;
use PhpSoftBox\Container\Factory\RequestedEntry;
use PhpSoftBox\Container\Profiler\ContainerProfilerCollector;
use PhpSoftBox\Profiler\ProfilerInterface;
use PhpSoftBox\Profiler\SpanInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use Stringable;
use Throwable;

use function array_all;
use function array_key_exists;
use function array_map;
use function array_pop;
use function array_search;
use function array_slice;
use function class_exists;
use function count;
use function explode;
use function get_class;
use function get_debug_type;
use function hrtime;
use function implode;
use function in_array;
use function is_a;
use function is_array;
use function is_bool;
use function is_callable;
use function is_float;
use function is_int;
use function is_null;
use function is_object;
use function is_string;
use function json_encode;
use function ksort;
use function method_exists;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function preg_replace_callback;
use function spl_object_id;
use function sprintf;
use function str_contains;
use function str_replace;
use function strlen;
use function strtolower;
use function strtoupper;
use function substr_count;
use function trim;
use function urlencode;

use const JSON_THROW_ON_ERROR;

class Container implements ContainerInterface, FactoryInterface, InvokerInterface
{
    private const INTERPOLATION_OPEN_ESCAPE  = "\0PSB_CONTAINER_OPEN_BRACE\0";
    private const INTERPOLATION_CLOSE_ESCAPE = "\0PSB_CONTAINER_CLOSE_BRACE\0";

    /**
     * @var array<string, DefinitionInterface>
     */
    private array $definitions = [];
    /**
     * @var array<string, list<DecoratorDefinition>>
     */
    private array $decorators = [];
    /**
     * @var array<string, mixed>
     */
    private array $resolved = [];
    /**
     * @var array<string, string|null>
     */
    private array $lazyEntries = [];
    /**
     * @var array<string, DefinitionInterface>
     */
    private array $wildcardDefinitions = [];
    /**
     * @var array<string, bool>
     */
    private array $resolving = [];
    /**
     * @var list<string>
     */
    private array $resolvingStack = [];
    /**
     * @var array<string, bool>
     */
    private array $resolvingLazyFactory = [];
    /**
     * @var array<string, ReflectionClass<object>>
     */
    private array $classReflectionCache = [];
    /**
     * @var array<string, ReflectionFunctionAbstract>
     */
    private array $callableReflectionCache = [];
    /**
     * @var list<string>
     */
    private array $requestedEntryStack = [];
    private ?ProfilerInterface $profiler;
    private ?ContainerProfilerCollector $profilerCollector;

    /**
     * @param array<string, mixed> $definitions
     * @param array<string, list<mixed>> $decorators
     * @param array<string, string|null> $lazyEntries
     */
    public function __construct(
        array $definitions = [],
        private readonly bool $autowiring = true,
        array $decorators = [],
        array $lazyEntries = [],
        private readonly bool $attributes = false,
        private readonly ?ContainerInterface $wrappedContainer = null,
        ?ProfilerInterface $profiler = null,
        ?ContainerProfilerCollector $profilerCollector = null,
    ) {
        $this->profiler          = $profiler;
        $this->profilerCollector = $profilerCollector;

        foreach ($definitions as $id => $definition) {
            $this->set($id, $definition);
        }

        if (!array_key_exists(self::class, $this->definitions)) {
            $this->definitions[self::class] = new ValueDefinition($this);

            $this->resolved[self::class] = $this;
        }

        if (!array_key_exists(ContainerInterface::class, $this->definitions)) {
            $this->definitions[ContainerInterface::class] = new AliasDefinition(self::class);
        }

        if (!array_key_exists(FactoryInterface::class, $this->definitions)) {
            $this->definitions[FactoryInterface::class] = new AliasDefinition(self::class);
        }

        if (!array_key_exists(InvokerInterface::class, $this->definitions)) {
            $this->definitions[InvokerInterface::class] = new AliasDefinition(self::class);
        }

        foreach ($decorators as $id => $entryDecorators) {
            foreach ($entryDecorators as $decorator) {
                $this->addDecorator($id, $decorator);
            }
        }

        foreach ($lazyEntries as $id => $className) {
            $this->addLazyEntry($id, $className);
        }
    }

    public function setProfiler(
        ?ProfilerInterface $profiler,
        ?ContainerProfilerCollector $collector = null,
    ): void {
        $this->profiler          = $profiler;
        $this->profilerCollector = $collector;
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->resolved)) {
            return $this->resolved[$id];
        }

        return $this->resolve($id, [], false, true);
    }

    public function has(string $id): bool
    {
        if (array_key_exists($id, $this->definitions)
            || $this->hasWildcardDefinition($id)
            || $this->canAutowireClass($id)
        ) {
            return true;
        }

        return $this->wrappedContainer?->has($id) ?? false;
    }

    public function set(string $id, mixed $value): void
    {
        if ($value instanceof DecoratorDefinition || $value instanceof DecoratorHelperInterface) {
            $this->addDecorator($id, $value);

            return;
        }

        if ($value instanceof LazyEntryHelper) {
            $targetId = $value->targetId($id);
            $this->addLazyEntry($targetId, $value->className());

            if ($targetId !== $id) {
                $this->definitions[$id] = new AliasDefinition($targetId);

                unset($this->resolved[$id]);
            }

            return;
        }

        if ($value instanceof AddDefinitionHelper || $value instanceof AddDefinition) {
            $this->addArrayDefinition($id, $value);

            return;
        }

        $definition = $this->normalizeDefinition($id, $value);

        if ($this->isWildcardId($id)) {
            $this->wildcardDefinitions[$id] = $definition;
            unset($this->resolved[$id]);

            return;
        }

        $this->definitions[$id] = $definition;
        unset($this->resolved[$id]);
    }

    /**
     * @param array<string|int, mixed> $parameters
     */
    public function make(string $id, array $parameters = []): mixed
    {
        return $this->resolve($id, $parameters, true, true);
    }

    /**
     * @param array<string|int, mixed> $parameters
     */
    public function call(mixed $callable, array $parameters = []): mixed
    {
        $callable = $this->normalizeCallable($callable);
        if (!is_callable($callable)) {
            throw new ContainerException(
                sprintf('Value is not callable: %s.', $this->callableDebugName($callable)),
            );
        }

        [$named, $positional] = $this->splitParameters($parameters);
        $reflection           = $this->reflectCallable($callable);
        $arguments            = [];
        $usedPositional       = [];

        foreach ($reflection->getParameters() as $index => $parameter) {
            if ($parameter->isVariadic()) {
                $this->appendVariadicNamedArguments($arguments, $named, $parameter->getName());
                $this->appendRemainingPositionalArguments($arguments, $positional, $usedPositional, $index);
                continue;
            }

            if (array_key_exists($parameter->getName(), $named)) {
                $arguments[] = $this->resolveParameterValue($named[$parameter->getName()]);
                continue;
            }

            if (array_key_exists($index, $positional)) {
                $usedPositional[$index] = true;
                $arguments[]            = $this->resolveParameterValue($positional[$index]);
                continue;
            }

            $fallback = $this->resolveParameterFallbackValue($parameter);
            if ($fallback['resolved']) {
                $arguments[] = $fallback['value'];
                continue;
            }

            throw new ContainerException(
                sprintf(
                    'Unable to resolve callable parameter "$%s" in %s.',
                    $parameter->getName(),
                    $this->callableDebugName($callable),
                ),
            );
        }

        return $this->invokeReflectedCallable($reflection, $callable, $arguments);
    }

    /**
     * @param array<string|int, mixed> $runtimeParameters
     * @param array<string|int, mixed> $definitionParameters
     *
     * @throws ReflectionException
     */
    public function instantiate(
        string $className,
        array $runtimeParameters = [],
        array $definitionParameters = [],
    ): object {
        $reflection = $this->getClassReflection($className);

        if (!$reflection->isInstantiable()) {
            throw new ContainerException('Class is not instantiable: ' . $className);
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return new $className();
        }

        [$runtimeNamed, $runtimePositional]       = $this->splitParameters($runtimeParameters);
        [$definitionNamed, $definitionPositional] = $this->splitParameters($definitionParameters);

        $arguments                = [];
        $usedRuntimePositional    = [];
        $usedDefinitionPositional = [];

        foreach ($constructor->getParameters() as $index => $parameter) {
            $name = $parameter->getName();

            if ($parameter->isVariadic()) {
                $this->appendVariadicNamedArguments($arguments, $runtimeNamed, $name);
                $this->appendRemainingPositionalArguments($arguments, $runtimePositional, $usedRuntimePositional, $index);
                $this->appendVariadicNamedArguments($arguments, $definitionNamed, $name);
                $this->appendRemainingPositionalArguments($arguments, $definitionPositional, $usedDefinitionPositional, $index);
                continue;
            }

            if (array_key_exists($name, $runtimeNamed)) {
                $arguments[] = $this->resolveParameterValue($runtimeNamed[$name]);
                continue;
            }

            if (array_key_exists($index, $runtimePositional)) {
                $usedRuntimePositional[$index] = true;
                $arguments[]                   = $this->resolveParameterValue($runtimePositional[$index]);
                continue;
            }

            if (array_key_exists($name, $definitionNamed)) {
                $arguments[] = $this->resolveParameterValue($definitionNamed[$name]);
                continue;
            }

            if (array_key_exists($index, $definitionPositional)) {
                $usedDefinitionPositional[$index] = true;
                $arguments[]                      = $this->resolveParameterValue($definitionPositional[$index]);
                continue;
            }

            $fallback = $this->resolveParameterFallbackValue($parameter);
            if ($fallback['resolved']) {
                $arguments[] = $fallback['value'];
                continue;
            }

            throw new ContainerException(
                sprintf(
                    'Unable to resolve constructor parameter "$%s" for %s.',
                    $name,
                    $className,
                ),
            );
        }

        return $reflection->newInstanceArgs($arguments);
    }

    public function autowiringEnabled(): bool
    {
        return $this->autowiring;
    }

    public function attributesEnabled(): bool
    {
        return $this->attributes;
    }

    public function diagnostics(): ContainerDiagnostics
    {
        return new ContainerDiagnostics(
            definitions: $this->definitions,
            resolved: $this->resolved,
            decorators: $this->decorators,
            lazyEntries: $this->lazyEntries,
            autowiring: $this->autowiring,
            attributes: $this->attributes,
            wrappedContainer: $this->wrappedContainer,
            canAutowireClass: fn (string $id): bool => $this->canAutowireClass($id),
            matchWildcardDefinition: fn (string $id): ?array => $this->matchWildcardDefinitionDetails($id),
            isImplicitLazyEntry: fn (string $id): bool => $this->isImplicitLazyEntry($id),
        );
    }

    public function canResolve(string $id): bool
    {
        return $this->diagnostics()->canResolve($id);
    }

    /**
     * @return array{
     *     id: string,
     *     resolvable: bool,
     *     source: string,
     *     definition?: string,
     *     pattern?: string,
     *     target?: string,
     *     lazy: bool,
     *     decorators: int,
     * }
     */
    public function why(string $id): array
    {
        return $this->diagnostics()->why($id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function trace(string $id): array
    {
        return $this->diagnostics()->trace($id);
    }

    /**
     * @return array<string, mixed>
     */
    public function graph(string $id): array
    {
        return $this->diagnostics()->graph($id);
    }

    /**
     * @return array{
     *     id: string,
     *     eligible: bool,
     *     kind: string|null,
     *     reasons: list<string>,
     *     pattern?: string,
     * }
     */
    public function aot(string $id): array
    {
        return $this->diagnostics()->aot($id);
    }

    /**
     * @return array<string, array{
     *     id: string,
     *     eligible: bool,
     *     kind: string|null,
     *     reasons: list<string>,
     * }>
     */
    public function aotPlan(): array
    {
        return $this->diagnostics()->aotPlan();
    }

    /**
     * @param list<string> $ids
     *
     * @return array{
     *     valid: bool,
     *     checked: list<string>,
     *     issues: list<array<string, mixed>>,
     * }
     */
    public function validate(array $ids = []): array
    {
        return $this->diagnostics()->validate($ids);
    }

    public function hasBaseDefinition(string $id): bool
    {
        return array_key_exists($id, $this->definitions)
            || $this->hasWildcardDefinition($id)
            || $this->canAutowireClass($id);
    }

    /**
     * @param array<string|int, mixed> $parameters
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function resolveBaseDefinition(string $id, array $parameters = [], bool $fresh = false): mixed
    {
        if (array_key_exists($id, $this->definitions)) {
            return $this->definitions[$id]->resolve($this, $id, $parameters, $fresh);
        }

        $wildcardDefinition = $this->matchWildcardDefinition($id);
        if ($wildcardDefinition !== null) {
            return $wildcardDefinition->resolve($this, $id, $parameters, $fresh);
        }

        if ($this->canAutowireClass($id)) {
            return $this->instantiate($id, $parameters);
        }

        if ($this->wrappedContainer !== null && $this->wrappedContainer->has($id)) {
            return $this->wrappedContainer->get($id);
        }

        throw new NotFoundException('Entry not found: ' . $id);
    }

    public function resolveInlineValue(mixed $value): mixed
    {
        return $this->resolveParameterValue($value);
    }

    public function interpolateString(string $value): string
    {
        if (!str_contains($value, '{')) {
            return str_replace(
                [self::INTERPOLATION_OPEN_ESCAPE, self::INTERPOLATION_CLOSE_ESCAPE],
                ['{', '}'],
                $value,
            );
        }

        $escaped = str_replace(
            ['{{', '}}'],
            [self::INTERPOLATION_OPEN_ESCAPE, self::INTERPOLATION_CLOSE_ESCAPE],
            $value,
        );

        $interpolated = (string) preg_replace_callback(
            '/\{([^{}]+)\}/',
            function (array $matches): string {
                return $this->resolveInterpolationExpression((string) $matches[1]);
            },
            $escaped,
        );

        return str_replace(
            [self::INTERPOLATION_OPEN_ESCAPE, self::INTERPOLATION_CLOSE_ESCAPE],
            ['{', '}'],
            $interpolated,
        );
    }

    public function stringifyValue(mixed $value, ?string $entryId = null): string
    {
        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        if (is_null($value)) {
            return '';
        }

        $entry = $entryId !== null ? '"' . $entryId . '"' : 'value';

        throw new ContainerException('Cannot interpolate non-stringable ' . $entry . '.');
    }

    /**
     * @param list<array{method: string, parameters: array<string|int, mixed>}> $methodCalls
     * @param array<string, mixed> $propertyInjections
     */
    public function injectObject(
        object $object,
        array $methodCalls = [],
        array $propertyInjections = [],
    ): object {
        foreach ($propertyInjections as $property => $value) {
            $this->assignProperty($object, $property, $value);
        }

        foreach ($methodCalls as $methodCall) {
            $method     = $methodCall['method'];
            $parameters = $methodCall['parameters'] ?? [];
            $this->call([$object, $method], $parameters);
        }

        if ($this->attributes) {
            $this->injectAttributesOnObject($object);
        }

        return $object;
    }

    public function injectOn(object $instance): void
    {
        $definition = $this->definitions[$instance::class] ?? $this->matchWildcardDefinition($instance::class);
        if ($definition instanceof ObjectDefinition) {
            $this->injectObject(
                $instance,
                $definition->methodCalls(),
                $definition->propertyInjections(),
            );

            return;
        }

        $this->injectObject($instance);
    }

    public function resolveCallableFactory(mixed $factory): callable
    {
        if (is_callable($factory)) {
            return $factory;
        }

        if (is_string($factory) && $factory !== '') {
            if (is_callable($factory)) {
                return $factory;
            }

            if ($this->has($factory)) {
                $resolved = $this->get($factory);
                if (is_callable($resolved)) {
                    return $resolved;
                }
            }
        }

        $type = get_debug_type($factory);

        throw new ContainerException('Factory must be callable, got ' . $type . '.');
    }

    /**
     * @param array<string|int, mixed> $parameters
     */
    private function resolve(string $id, array $parameters, bool $fresh, bool $allowLazy): mixed
    {
        if (!$fresh && array_key_exists($id, $this->resolved) && !$this->isResolvingLazyFactory($id)) {
            $cached = $this->resolved[$id];
            if ($allowLazy || !$this->isUninitializedLazyObject($cached)) {
                $this->profilerCollector?->recordResolve(
                    $id,
                    durationMs: 0.0,
                    cached: true,
                    fresh: false,
                    lazy: $this->isLazyEntry($id) || $this->isImplicitLazyEntry($id),
                );

                return $cached;
            }
        }

        if (isset($this->resolving[$id])) {
            $exception = new ContainerException('Circular dependency detected: ' . $this->formatCircularPath($id));
            $profile   = $this->startProfilerResolve($id, $fresh, $allowLazy);

            $this->failProfilerResolve($profile, $exception);
            $this->finishProfilerResolve($profile);

            throw $exception;
        }

        $profile = $this->startProfilerResolve($id, $fresh, $allowLazy);

        $this->resolving[$id]        = true;
        $this->resolvingStack[]      = $id;
        $this->requestedEntryStack[] = $id;

        try {
            if ($allowLazy && ($this->isLazyEntry($id) || $this->isImplicitLazyEntry($id))) {
                $value = $this->createLazyProxy($id, $parameters, $fresh);
                if (!$fresh) {
                    $this->resolved[$id] = $value;
                }

                return $value;
            }

            if (array_key_exists($id, $this->definitions)) {
                $value = $this->definitions[$id]->resolve($this, $id, $parameters, $fresh);
                $value = $this->applyDecorators($id, $value, $parameters);
                if (!$fresh && !$this->isResolvingLazyFactory($id)) {
                    $this->resolved[$id] = $value;
                }

                return $value;
            }

            $wildcardDefinition = $this->matchWildcardDefinition($id);
            if ($wildcardDefinition !== null) {
                $value = $wildcardDefinition->resolve($this, $id, $parameters, $fresh);
                $value = $this->applyDecorators($id, $value, $parameters);

                if (!$fresh && !$this->isResolvingLazyFactory($id)) {
                    $this->resolved[$id] = $value;
                }

                return $value;
            }

            if ($this->canAutowireClass($id)) {
                $value = $this->instantiate($id, $parameters);
                $value = $this->injectObject($value);
                $value = $this->applyDecorators($id, $value, $parameters);
                if (!$fresh && !$this->isResolvingLazyFactory($id)) {
                    $this->resolved[$id] = $value;
                }

                return $value;
            }

            if ($this->wrappedContainer !== null && $this->wrappedContainer->has($id)) {
                $value = $this->wrappedContainer->get($id);
                $value = $this->applyDecorators($id, $value, $parameters);

                if (!$fresh && !$this->isResolvingLazyFactory($id)) {
                    $this->resolved[$id] = $value;
                }

                return $value;
            }

            throw new NotFoundException('Entry not found: ' . $id);
        } catch (Throwable $exception) {
            $this->failProfilerResolve($profile, $exception);

            throw $exception;
        } finally {
            unset($this->resolving[$id]);
            array_pop($this->resolvingStack);
            array_pop($this->requestedEntryStack);
            $this->finishProfilerResolve($profile);
        }
    }

    /**
     * @return array{started_at: int, id: string, fresh: bool, lazy: bool, span: SpanInterface|null, failed: bool, exception_class: string|null}|null
     */
    private function startProfilerResolve(string $id, bool $fresh, bool $allowLazy): ?array
    {
        if ($this->profilerCollector === null && ($this->profiler === null || !$this->profiler->enabled())) {
            return null;
        }

        $lazy = $allowLazy && ($this->isLazyEntry($id) || $this->isImplicitLazyEntry($id));
        $span = null;

        if ($this->profiler !== null
            && $this->profiler->enabled()
            && ($this->profilerCollector?->traceResolves() ?? true)
        ) {
            $span = $this->profiler->start('container.resolve', [
                'id'    => $id,
                'fresh' => $fresh,
                'lazy'  => $lazy,
            ], 'container');
        }

        return [
            'started_at'      => hrtime(true),
            'id'              => $id,
            'fresh'           => $fresh,
            'lazy'            => $lazy,
            'span'            => $span,
            'failed'          => false,
            'exception_class' => null,
        ];
    }

    /**
     * @param array{started_at: int, id: string, fresh: bool, lazy: bool, span: SpanInterface|null, failed: bool, exception_class: string|null}|null $profile
     */
    private function failProfilerResolve(?array &$profile, Throwable $exception): void
    {
        if ($profile === null) {
            return;
        }

        $profile['failed']          = true;
        $profile['exception_class'] = get_class($exception);
        $profile['span']?->fail($exception);
    }

    /**
     * @param array{started_at: int, id: string, fresh: bool, lazy: bool, span: SpanInterface|null, failed: bool, exception_class: string|null}|null $profile
     */
    private function finishProfilerResolve(?array $profile): void
    {
        if ($profile === null) {
            return;
        }

        $durationMs = (hrtime(true) - $profile['started_at']) / 1_000_000;

        $this->profilerCollector?->recordResolve(
            $profile['id'],
            durationMs: $durationMs,
            cached: false,
            fresh: $profile['fresh'],
            lazy: $profile['lazy'],
            failed: $profile['failed'],
            exceptionClass: $profile['exception_class'],
        );

        $profile['span']?->finish();
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

    private function addArrayDefinition(string $id, mixed $value): void
    {
        $addDefinition = $value instanceof AddDefinition ? $value : $value->toDefinition($id);
        $addItems      = $addDefinition->items();

        if (!array_key_exists($id, $this->definitions)) {
            $this->definitions[$id] = new ValueDefinition($addItems);

            unset($this->resolved[$id]);

            return;
        }

        $existing = $this->definitions[$id];
        if (!$existing instanceof ValueDefinition || !is_array($existing->value())) {
            throw new ContainerException('add() can be applied only to array entries: ' . $id);
        }

        $this->definitions[$id] = new ValueDefinition(
            AddDefinition::merge($existing->value(), $addItems, $addDefinition->strategy()),
        );

        unset($this->resolved[$id]);
    }

    private function isWildcardId(string $id): bool
    {
        return str_contains($id, '*');
    }

    private function hasWildcardDefinition(string $id): bool
    {
        return $this->matchWildcardDefinition($id) !== null;
    }

    private function isImplicitLazyEntry(string $id): bool
    {
        if (!$this->attributes || !class_exists($id)) {
            return false;
        }

        $reflection = $this->getClassReflection($id);
        $attributes = $reflection->getAttributes(Injectable::class, ReflectionAttribute::IS_INSTANCEOF);
        if ($attributes === []) {
            return false;
        }

        /** @var Injectable $injectable */
        $injectable = $attributes[0]->newInstance();

        return $injectable->lazy;
    }

    private function addDecorator(string $id, mixed $decorator): void
    {
        $this->decorators[$id][] = $this->normalizeDecorator($id, $decorator);
        unset($this->resolved[$id]);
    }

    private function addLazyEntry(string $id, ?string $className): void
    {
        $this->lazyEntries[$id] = $className;
        unset($this->resolved[$id]);
    }

    private function isLazyEntry(string $id): bool
    {
        return array_key_exists($id, $this->lazyEntries);
    }

    private function isResolvingLazyFactory(string $id): bool
    {
        return $this->resolvingLazyFactory[$id] ?? false;
    }

    private function normalizeDecorator(string $id, mixed $decorator): DecoratorDefinition
    {
        if ($decorator instanceof DecoratorDefinition) {
            return $decorator;
        }

        if ($decorator instanceof DecoratorHelperInterface) {
            return $decorator->toDecorator($id);
        }

        return new DecoratorDefinition($decorator);
    }

    /**
     * @param array<string|int, mixed> $parameters
     */
    protected function applyDecorators(string $id, mixed $value, array $parameters = []): mixed
    {
        if (!array_key_exists($id, $this->decorators)) {
            return $value;
        }

        foreach ($this->decorators[$id] as $decorator) {
            $value = $decorator->decorate($this, $id, $value, $parameters);
        }

        return $value;
    }

    protected function resolveCompiledEntry(string $id, callable $resolver): mixed
    {
        if (isset($this->resolving[$id])) {
            $exception = new ContainerException('Circular dependency detected: ' . $this->formatCircularPath($id));
            $profile   = $this->startProfilerResolve($id, fresh: false, allowLazy: false);

            $this->failProfilerResolve($profile, $exception);
            $this->finishProfilerResolve($profile);

            throw $exception;
        }

        $profile = $this->startProfilerResolve($id, fresh: false, allowLazy: false);

        $this->resolving[$id]        = true;
        $this->resolvingStack[]      = $id;
        $this->requestedEntryStack[] = $id;

        try {
            return $resolver();
        } catch (Throwable $exception) {
            $this->failProfilerResolve($profile, $exception);

            throw $exception;
        } finally {
            unset($this->resolving[$id]);
            array_pop($this->resolvingStack);
            array_pop($this->requestedEntryStack);
            $this->finishProfilerResolve($profile);
        }
    }

    /**
     * @param array<string|int, mixed> $parameters
     */
    protected function resolveFactoryEntry(string $id, array $parameters = []): mixed
    {
        $definition = $this->definitions[$id] ?? null;
        if (!$definition instanceof FactoryDefinition) {
            throw new ContainerException('Compiled factory entry not found: ' . $id);
        }

        $callable = $this->resolveCallableFactory($definition->factory());

        try {
            return $this->call(
                $callable,
                [
                    ...$parameters,
                    'requestedEntry' => new RequestedEntry($id),
                    'container'      => $this,
                ],
            );
        } catch (Throwable $exception) {
            throw new ContainerException(
                'Failed to resolve factory for entry "' . $id . '": ' . $exception->getMessage(),
                0,
                $exception,
            );
        }
    }

    /**
     * @param array<string|int, mixed> $parameters
     */
    private function createLazyProxy(string $id, array $parameters, bool $fresh): object
    {
        $className = $this->resolveLazyClassName($id, []);
        if ($className === null) {
            throw new ContainerException(
                sprintf(
                    'Lazy entry "%s" requires a concrete class. Configure class name in ContainerBuilder::lazy().',
                    $id,
                ),
            );
        }

        $reflection = $this->getClassReflection($className);
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

        $lazy = $reflection->newLazyProxy(function () use ($id, $parameters, $fresh, $className): object {
            $this->resolvingLazyFactory[$id] = true;

            try {
                $resolved = $this->resolve($id, $parameters, $fresh, false);
            } finally {
                unset($this->resolvingLazyFactory[$id]);
            }

            if (!is_object($resolved)) {
                throw new ContainerException(
                    sprintf(
                        'Lazy entry "%s" must resolve to object, got %s.',
                        $id,
                        get_debug_type($resolved),
                    ),
                );
            }

            if (!($resolved instanceof $className)) {
                throw new ContainerException(
                    sprintf(
                        'Lazy entry "%s" resolved incompatible object %s, expected %s.',
                        $id,
                        get_class($resolved),
                        $className,
                    ),
                );
            }

            return $resolved;
        });

        if (!is_object($lazy)) {
            throw new ContainerException(sprintf('Lazy entry "%s" could not create lazy proxy.', $id));
        }

        return $lazy;
    }

    /**
     * @param list<string> $visited
     */
    private function resolveLazyClassName(string $id, array $visited): ?string
    {
        if (in_array($id, $visited, true)) {
            return null;
        }

        $visited[]  = $id;
        $configured = $this->lazyEntries[$id] ?? null;
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        if (class_exists($id)) {
            return $id;
        }

        $definition = $this->definitions[$id] ?? $this->matchWildcardDefinition($id);
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
        return $this->matchWildcardDefinitionDetails($id)['definition'] ?? null;
    }

    /**
     * @return array{pattern: string, definition: DefinitionInterface}|null
     */
    private function matchWildcardDefinitionDetails(string $id): ?array
    {
        $best           = null;
        $bestWildcards  = null;
        $bestLiteralLen = null;
        $order          = 0;
        $bestOrder      = null;

        foreach ($this->wildcardDefinitions as $pattern => $definition) {
            $regex = $this->wildcardRegex($pattern);
            if ($regex === null) {
                $order++;
                continue;
            }

            $matches = [];
            if (preg_match($regex, $id, $matches) !== 1) {
                $order++;
                continue;
            }

            $wildcards  = substr_count($pattern, '*');
            $literalLen = strlen(str_replace('*', '', $pattern));
            if ($best === null
                || $wildcards < $bestWildcards
                || ($wildcards === $bestWildcards && $literalLen > $bestLiteralLen)
                || ($wildcards === $bestWildcards && $literalLen === $bestLiteralLen && $order < $bestOrder)
            ) {
                $best = [
                    'pattern'    => $pattern,
                    'definition' => $this->materializeWildcardDefinition($definition, $matches, $id),
                ];
                $bestWildcards  = $wildcards;
                $bestLiteralLen = $literalLen;
                $bestOrder      = $order;
            }

            $order++;
        }

        return $best;
    }

    private function wildcardRegex(string $pattern): ?string
    {
        if (!str_contains($pattern, '*')) {
            return null;
        }

        $quoted = preg_quote($pattern, '/');
        $quoted = preg_replace('/\\\\\*/', '([^\\\\\\\\]+)', $quoted);
        if ($quoted === null) {
            return null;
        }

        return '/^' . $quoted . '$/';
    }

    /**
     * @param array<int, string> $matches
     */
    private function materializeWildcardDefinition(
        DefinitionInterface $definition,
        array $matches,
        string $entryId,
    ): DefinitionInterface {
        $wildcards = array_slice($matches, 1);
        if ($wildcards === []) {
            return $definition;
        }

        if ($definition instanceof AliasDefinition) {
            return new AliasDefinition($this->replaceWildcards($definition->targetId(), $wildcards));
        }

        if ($definition instanceof ObjectDefinition) {
            return new ObjectDefinition(
                className: $this->replaceWildcards($definition->className($entryId), $wildcards),
                constructorParameters: $definition->constructorParameters(),
                methodCalls: $definition->methodCalls(),
                propertyInjections: $definition->propertyInjections(),
            );
        }

        if ($definition instanceof StringDefinition) {
            return new StringDefinition($this->replaceWildcards($definition->value(), $wildcards));
        }

        return $definition;
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

        $reflection = $this->reflectCallable($factory);
        $type       = $reflection->getReturnType();
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

            $name = $type->getName();

            return class_exists($name) ? $name : null;
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

    private function isUninitializedLazyObject(mixed $value): bool
    {
        if (!is_object($value)) {
            return false;
        }

        try {
            return new ReflectionClass($value)->isUninitializedLazyObject($value);
        } catch (ReflectionException) {
            return false;
        }
    }

    private function reflectCallable(callable $callable): ReflectionFunctionAbstract
    {
        $cacheKey = null;

        if (is_array($callable) && array_key_exists(0, $callable) && array_key_exists(1, $callable)) {
            $target = $callable[0];
            $method = $callable[1];

            $cacheTarget = is_object($target)
                ? $target::class . '#' . spl_object_id($target)
                : (string) $target;

            $cacheKey = $cacheTarget . '::' . $method;
        } elseif (is_object($callable) && !($callable instanceof Closure)) {
            $cacheKey = $callable::class . '::__invoke';
        } elseif (is_string($callable)) {
            $cacheKey = 'function:' . $callable;
        } elseif ($callable instanceof Closure) {
            $cacheKey = 'closure#' . spl_object_id($callable);
        }

        if ($cacheKey !== null && array_key_exists($cacheKey, $this->callableReflectionCache)) {
            return $this->callableReflectionCache[$cacheKey];
        }

        $reflection = null;

        if (is_array($callable) && isset($callable[0], $callable[1])) {
            $reflection = new ReflectionMethod($callable[0], (string) $callable[1]);
        } elseif (is_object($callable) && !($callable instanceof Closure)) {
            $reflection = new ReflectionMethod($callable, '__invoke');
        } else {
            $reflection = new ReflectionFunction($callable);
        }

        if ($cacheKey !== null) {
            $this->callableReflectionCache[$cacheKey] = $reflection;
        }

        return $reflection;
    }

    /**
     * @param list<mixed> $arguments
     */
    private function invokeReflectedCallable(
        ReflectionFunctionAbstract $reflection,
        callable $callable,
        array $arguments,
    ): mixed {
        if ($reflection instanceof ReflectionMethod) {
            $target = null;
            if (is_array($callable) && isset($callable[0]) && is_object($callable[0])) {
                $target = $callable[0];
            } elseif (is_object($callable) && !($callable instanceof Closure)) {
                $target = $callable;
            }

            return $reflection->invokeArgs($target, $arguments);
        }

        if ($reflection instanceof ReflectionFunction) {
            return $reflection->invokeArgs($arguments);
        }

        return $callable(...$arguments);
    }

    private function normalizeCallable(mixed $callable): mixed
    {
        if (!is_array($callable) || !isset($callable[0], $callable[1])) {
            return $callable;
        }

        [$class, $method] = $callable;

        if (!is_string($class) || !is_string($method)) {
            return $callable;
        }

        if (!class_exists($class) || !method_exists($class, $method)) {
            return $callable;
        }

        $reflection = new ReflectionMethod($class, $method);

        if ($reflection->isStatic()) {
            return $callable;
        }

        $instance = $this->get($class);

        return [$instance, $method];
    }

    /**
     * @param array<string|int, mixed> $parameters
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

        if ($positional !== []) {
            ksort($positional);
        }

        return [$named, $positional];
    }

    private function canResolveClassType(ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();
        if (!$type instanceof ReflectionType) {
            return false;
        }

        return $this->canResolveParameterType($type);
    }

    private function resolveClassType(ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();
        if (!$type instanceof ReflectionType) {
            throw new ContainerException('Invalid class type resolution request.');
        }

        return $this->resolveParameterType($type);
    }

    /**
     * @return array{resolved: bool, value: mixed}
     */
    private function resolveParameterFallbackValue(ReflectionParameter $parameter): array
    {
        $attributeResolved = $this->resolveInjectAttributeForParameter($parameter);
        if ($attributeResolved['resolved']) {
            return $attributeResolved;
        }

        if ($this->canResolveClassType($parameter)) {
            return [
                'resolved' => true,
                'value'    => $this->resolveClassType($parameter),
            ];
        }

        if ($parameter->isDefaultValueAvailable()) {
            return [
                'resolved' => true,
                'value'    => $parameter->getDefaultValue(),
            ];
        }

        if ($this->parameterAllowsNull($parameter)) {
            return [
                'resolved' => true,
                'value'    => null,
            ];
        }

        return ['resolved' => false, 'value' => null];
    }

    private function parameterAllowsNull(ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();
        if (!$type instanceof ReflectionType) {
            return true;
        }

        return $type->allowsNull();
    }

    private function canResolveParameterType(ReflectionType $type): bool
    {
        if ($type instanceof ReflectionNamedType) {
            return !$type->isBuiltin() && $this->hasResolvableClassType($type->getName());
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $unionType) {
                if ($unionType instanceof ReflectionNamedType && !$unionType->isBuiltin() && $this->hasResolvableClassType($unionType->getName())) {
                    return true;
                }

                if ($unionType instanceof ReflectionIntersectionType && $this->canResolveIntersectionType($unionType)) {
                    return true;
                }
            }

            return false;
        }

        if ($type instanceof ReflectionIntersectionType) {
            return $this->canResolveIntersectionType($type);
        }

        return false;
    }

    private function resolveParameterType(ReflectionType $type): mixed
    {
        if ($type instanceof ReflectionNamedType) {
            if ($type->isBuiltin()) {
                throw new ContainerException('Unable to resolve built-in type: ' . $type->getName());
            }

            return $this->resolveNamedClassType($type->getName());
        }

        if ($type instanceof ReflectionUnionType) {
            return $this->resolveUnionType($type);
        }

        if ($type instanceof ReflectionIntersectionType) {
            return $this->resolveIntersectionType($type);
        }

        throw new ContainerException('Unsupported parameter type: ' . $type::class);
    }

    private function resolveNamedClassType(string $className): mixed
    {
        if ($className === RequestedEntry::class) {
            $entry = $this->requestedEntryStack !== []
                ? $this->requestedEntryStack[count($this->requestedEntryStack) - 1]
                : '';

            return new RequestedEntry($entry);
        }

        if ($this->hasResolvableClassType($className)) {
            return $this->get($className);
        }

        throw new ContainerException('Unable to resolve class type: ' . $className);
    }

    private function resolveUnionType(ReflectionUnionType $type): mixed
    {
        $candidates = [];
        $typeNames  = [];

        foreach ($type->getTypes() as $unionType) {
            if ($unionType instanceof ReflectionNamedType) {
                if ($unionType->isBuiltin()) {
                    continue;
                }

                $name        = $unionType->getName();
                $typeNames[] = $name;

                if ($this->hasResolvableClassType($name)) {
                    $candidates[] = ['kind' => 'named', 'value' => $name];
                }

                continue;
            }

            if ($unionType instanceof ReflectionIntersectionType) {
                $typeNames[] = $this->formatIntersectionType($unionType);

                if ($this->canResolveIntersectionType($unionType)) {
                    $candidates[] = ['kind' => 'intersection', 'value' => $unionType];
                }
            }
        }

        if ($candidates === []) {
            throw new ContainerException('Unable to resolve union type: ' . implode('|', $typeNames));
        }

        if (count($candidates) > 1) {
            throw new ContainerException('Ambiguous union type resolution: ' . implode('|', $typeNames));
        }

        $candidate = $candidates[0];

        return match ($candidate['kind']) {
            'named'        => $this->get((string) $candidate['value']),
            'intersection' => $this->resolveIntersectionType($candidate['value']),
            default        => throw new ContainerException('Unsupported union resolution candidate kind.'),
        };
    }

    private function canResolveIntersectionType(ReflectionIntersectionType $type): bool
    {
        foreach ($type->getTypes() as $namedType) {
            if (!$namedType instanceof ReflectionNamedType || $namedType->isBuiltin()) {
                continue;
            }

            if ($this->hasResolvableClassType($namedType->getName())) {
                return true;
            }
        }

        return false;
    }

    private function resolveIntersectionType(ReflectionIntersectionType $type): object
    {
        $typeNames = [];

        foreach ($type->getTypes() as $namedType) {
            if ($namedType instanceof ReflectionNamedType) {
                $typeNames[] = $namedType->getName();
            }
        }

        foreach ($typeNames as $typeName) {
            if (!$this->hasResolvableClassType($typeName)) {
                continue;
            }

            $candidate = $this->get($typeName);
            if (!$this->matchesIntersectionTypes($candidate, $typeNames)) {
                continue;
            }

            return $candidate;
        }

        throw new ContainerException('Unable to resolve intersection type: ' . implode('&', $typeNames));
    }

    /**
     * @param list<string> $typeNames
     */
    private function matchesIntersectionTypes(mixed $candidate, array $typeNames): bool
    {
        if (!is_object($candidate)) {
            return false;
        }

        return array_all($typeNames, fn ($typeName) => is_a($candidate, $typeName));

    }

    private function hasResolvableClassType(string $className): bool
    {
        if ($className === RequestedEntry::class) {
            return true;
        }

        return array_key_exists($className, $this->definitions)
            || $this->hasWildcardDefinition($className)
            || $this->canAutowireClass($className)
            || ($this->wrappedContainer?->has($className) ?? false);
    }

    private function canAutowireClass(string $className): bool
    {
        if (!$this->autowiring || !class_exists($className)) {
            return false;
        }

        try {
            $reflection = $this->getClassReflection($className);
        } catch (ContainerException) {
            return false;
        }

        return $reflection->isInstantiable();
    }

    /**
     * @return ReflectionClass<object>
     */
    private function getClassReflection(string $className): ReflectionClass
    {
        if (array_key_exists($className, $this->classReflectionCache)) {
            return $this->classReflectionCache[$className];
        }

        if (!class_exists($className)) {
            throw new NotFoundException('Class does not exist: ' . $className);
        }

        try {
            $reflection = new ReflectionClass($className);
        } catch (ReflectionException $exception) {
            throw new ContainerException('Unable to reflect class: ' . $className, 0, $exception);
        }

        $this->classReflectionCache[$className] = $reflection;

        return $reflection;
    }

    private function formatIntersectionType(ReflectionIntersectionType $type): string
    {
        $names = [];

        foreach ($type->getTypes() as $namedType) {
            if ($namedType instanceof ReflectionNamedType) {
                $names[] = $namedType->getName();
            }
        }

        return implode('&', $names);
    }

    /**
     * @param array<string, mixed> $named
     * @param array<int, mixed> $arguments
     */
    private function appendVariadicNamedArguments(array &$arguments, array $named, string $parameterName): void
    {
        if (!array_key_exists($parameterName, $named)) {
            return;
        }

        $values = $named[$parameterName];
        if (!is_array($values)) {
            $values = [$values];
        }

        foreach ($values as $value) {
            $arguments[] = $this->resolveParameterValue($value);
        }
    }

    /**
     * @param array<int, mixed> $arguments
     * @param array<int, mixed> $positional
     * @param array<int, bool> $usedPositional
     */
    private function appendRemainingPositionalArguments(
        array &$arguments,
        array $positional,
        array &$usedPositional,
        int $startIndex,
    ): void {
        foreach ($positional as $position => $value) {
            if ($position < $startIndex || isset($usedPositional[$position])) {
                continue;
            }

            $arguments[]               = $this->resolveParameterValue($value);
            $usedPositional[$position] = true;
        }
    }

    private function formatCircularPath(string $id): string
    {
        $start = array_search($id, $this->resolvingStack, true);
        if ($start === false) {
            return $id . ' -> ' . $id;
        }

        $path   = array_slice($this->resolvingStack, $start);
        $path[] = $id;

        return implode(' -> ', $path);
    }

    private function resolveParameterValue(mixed $value): mixed
    {
        if ($value instanceof DefinitionHelperInterface) {
            $tmpId = '__inline__' . spl_object_id($value);

            return $value->toDefinition($tmpId)->resolve($this, $tmpId, [], true);
        }

        if ($value instanceof DefinitionInterface) {
            $tmpId = '__inline__' . spl_object_id($value);

            return $value->resolve($this, $tmpId, [], true);
        }

        if (is_array($value)) {
            return array_map(function ($item) {
                return $this->resolveParameterValue($item);
            }, $value);
        }

        if (is_string($value) && str_contains($value, '{')) {
            return $this->interpolateString($value);
        }

        return $value;
    }

    private function resolveInterpolationExpression(string $expression): string
    {
        $parts   = array_map(static fn (string $part): string => trim($part), explode('|', $expression));
        $entryId = $parts[0] ?? '';
        if ($entryId === '') {
            throw new ContainerException('Interpolation expression cannot be empty.');
        }

        $resolved = $this->get($entryId);
        $value    = null;

        foreach (array_slice($parts, 1) as $transform) {
            if ($transform === '') {
                continue;
            }

            if (strtolower($transform) === 'json') {
                $value = (string) json_encode($resolved, JSON_THROW_ON_ERROR);

                continue;
            }

            $value ??= $this->stringifyValue($resolved, $entryId);
            $value = $this->applyInterpolationTransform($value, $entryId, $transform);
        }

        return $value ?? $this->stringifyValue($resolved, $entryId);
    }

    private function applyInterpolationTransform(
        string $value,
        string $entryId,
        string $transform,
    ): string {
        return match (strtolower($transform)) {
            'trim'      => trim($value),
            'upper'     => strtoupper($value),
            'lower'     => strtolower($value),
            'urlencode' => urlencode($value),
            default     => throw new ContainerException(
                sprintf('Unknown interpolation transform "%s" for entry "%s".', $transform, $entryId),
            ),
        };
    }

    /**
     * @return array{resolved: bool, value: mixed}
     */
    private function resolveInjectAttributeForParameter(ReflectionParameter $parameter): array
    {
        if (!$this->attributes) {
            return ['resolved' => false, 'value' => null];
        }

        $attributes = $parameter->getAttributes(Inject::class, ReflectionAttribute::IS_INSTANCEOF);
        if ($attributes === []) {
            return ['resolved' => false, 'value' => null];
        }

        /** @var Inject $inject */
        $inject = $attributes[0]->newInstance();

        if ($inject->name !== null) {
            return [
                'resolved' => true,
                'value'    => $this->resolveInjectName($inject->name, $parameter),
            ];
        }

        if ($this->canResolveClassType($parameter)) {
            return [
                'resolved' => true,
                'value'    => $this->resolveClassType($parameter),
            ];
        }

        return ['resolved' => false, 'value' => null];
    }

    private function resolveInjectName(string|array $name, ReflectionParameter $parameter): mixed
    {
        if (is_string($name)) {
            return $this->resolveParameterValue($this->get($name));
        }

        if (array_key_exists($parameter->getName(), $name)) {
            return $this->resolveParameterValue($this->get((string) $name[$parameter->getName()]));
        }

        if (array_key_exists(0, $name)) {
            return $this->resolveParameterValue($this->get((string) $name[0]));
        }

        if ($this->canResolveClassType($parameter)) {
            return $this->resolveClassType($parameter);
        }

        throw new ContainerException(
            sprintf('Unable to resolve #[Inject] for parameter "$%s".', $parameter->getName()),
        );
    }

    private function injectAttributesOnObject(object $object): void
    {
        $reflection = new ReflectionClass($object);

        foreach ($reflection->getProperties() as $property) {
            $attributes = $property->getAttributes(Inject::class, ReflectionAttribute::IS_INSTANCEOF);
            if ($attributes === []) {
                continue;
            }

            if ($property->isReadOnly() && $property->isInitialized($object)) {
                continue;
            }

            /** @var Inject $inject */
            $inject = $attributes[0]->newInstance();
            $this->assignPropertyByAttribute($object, $property, $inject);
        }

        foreach ($reflection->getMethods() as $method) {
            if ($method->isStatic() || $method->isConstructor()) {
                continue;
            }

            $attributes = $method->getAttributes(Inject::class, ReflectionAttribute::IS_INSTANCEOF);
            if ($attributes === []) {
                continue;
            }

            /** @var Inject $inject */
            $inject           = $attributes[0]->newInstance();
            $methodParameters = [];
            $methodName       = $method->getName();
            foreach ($method->getParameters() as $index => $parameter) {
                if (is_array($inject->name)) {
                    if (array_key_exists($parameter->getName(), $inject->name)) {
                        $methodParameters[$parameter->getName()] = $this->get((string) $inject->name[$parameter->getName()]);
                        continue;
                    }
                    if (array_key_exists($index, $inject->name)) {
                        $methodParameters[$index] = $this->get((string) $inject->name[$index]);
                        continue;
                    }
                } elseif (is_string($inject->name) && $index === 0) {
                    $methodParameters[$index] = $this->get($inject->name);
                    continue;
                }

                $parameterAttribute = $parameter->getAttributes(Inject::class, ReflectionAttribute::IS_INSTANCEOF);
                if ($parameterAttribute !== []) {
                    /** @var Inject $parameterInject */
                    $parameterInject = $parameterAttribute[0]->newInstance();
                    if ($parameterInject->name !== null) {
                        $methodParameters[$parameter->getName()] = $this->resolveInjectName($parameterInject->name, $parameter);
                    }
                }
            }

            $this->call([$object, $methodName], $methodParameters);
        }
    }

    private function assignProperty(object $object, string $property, mixed $value): void
    {
        try {
            $reflection = new ReflectionProperty($object, $property);

            $reflection->setValue($object, $this->resolveParameterValue($value));
        } catch (ReflectionException $exception) {
            throw new ContainerException(
                sprintf('Unable to inject property %s::%s.', $object::class, $property),
                0,
                $exception,
            );
        }
    }

    private function assignPropertyByAttribute(object $object, ReflectionProperty $property, Inject $inject): void
    {
        try {
            if ($inject->name !== null) {
                if (!is_string($inject->name)) {
                    throw new ContainerException(
                        sprintf(
                            '#[Inject] on property %s::%s expects string identifier.',
                            $property->getDeclaringClass()->getName(),
                            $property->getName(),
                        ),
                    );
                }

                $property->setValue($object, $this->get($inject->name));

                return;
            }

            $type = $property->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $property->setValue($object, $this->get($type->getName()));

                return;
            }
        } catch (Throwable $exception) {
            throw new ContainerException(
                sprintf(
                    'Unable to inject property %s::%s via #[Inject].',
                    $property->getDeclaringClass()->getName(),
                    $property->getName(),
                ),
                0,
                $exception,
            );
        }

        throw new ContainerException(
            sprintf(
                'Unable to autowire property %s::%s via #[Inject].',
                $property->getDeclaringClass()->getName(),
                $property->getName(),
            ),
        );
    }

    private function callableDebugName(mixed $callable): string
    {
        if (is_array($callable) && isset($callable[0], $callable[1])) {
            $target = is_object($callable[0]) ? $callable[0]::class : (string) $callable[0];

            return $target . '::' . (string) $callable[1];
        }

        if (is_object($callable)) {
            return $callable::class . '::__invoke';
        }

        if (is_string($callable)) {
            return $callable;
        }

        return get_debug_type($callable);
    }
}
