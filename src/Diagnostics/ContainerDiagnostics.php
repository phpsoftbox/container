<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Diagnostics;

use Closure;
use PhpSoftBox\Container\Attribute\Inject;
use PhpSoftBox\Container\Compilation\AotCompilationPlan;
use PhpSoftBox\Container\Compilation\AotEligibilityAnalyzer;
use PhpSoftBox\Container\Definition\AliasDefinition;
use PhpSoftBox\Container\Definition\DecoratorDefinition;
use PhpSoftBox\Container\Definition\DefinitionInterface;
use PhpSoftBox\Container\Definition\FactoryDefinition;
use PhpSoftBox\Container\Definition\Helper\DefinitionHelperInterface;
use PhpSoftBox\Container\Definition\ObjectDefinition;
use PhpSoftBox\Container\Definition\StringDefinition;
use PhpSoftBox\Container\Definition\ValueDefinition;
use PhpSoftBox\Container\Factory\RequestedEntry;
use Psr\Container\ContainerInterface;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;

use function array_key_exists;
use function array_keys;
use function array_unique;
use function array_values;
use function class_exists;
use function count;
use function explode;
use function is_array;
use function is_int;
use function is_string;
use function preg_match_all;
use function trim;

final readonly class ContainerDiagnostics
{
    /**
     * @param array<string, DefinitionInterface> $definitions
     * @param array<string, mixed> $resolved
     * @param array<string, list<DecoratorDefinition>> $decorators
     * @param array<string, string|null> $lazyEntries
     * @param Closure(string): bool $canAutowireClass
     * @param Closure(string): (array{pattern: string, definition: DefinitionInterface}|null) $matchWildcardDefinition
     * @param Closure(string): bool $isImplicitLazyEntry
     */
    public function __construct(
        private array $definitions,
        private array $resolved,
        private array $decorators,
        private array $lazyEntries,
        private bool $autowiring,
        private bool $attributes,
        private ?ContainerInterface $wrappedContainer,
        private Closure $canAutowireClass,
        private Closure $matchWildcardDefinition,
        private Closure $isImplicitLazyEntry,
    ) {
    }

    public function canResolve(string $id): bool
    {
        return $this->why($id)['resolvable'];
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
        if (array_key_exists($id, $this->resolved)) {
            return $this->resolutionInfo($id, true, 'resolved');
        }

        if (array_key_exists($id, $this->definitions)) {
            $info = $this->resolutionInfo($id, true, 'definition');
            $this->appendDefinitionDiagnostics($info, $this->definitions[$id]);

            return $info;
        }

        $wildcard = ($this->matchWildcardDefinition)($id);
        if ($wildcard !== null) {
            $info            = $this->resolutionInfo($id, true, 'wildcard');
            $info['pattern'] = $wildcard['pattern'];
            $this->appendDefinitionDiagnostics($info, $wildcard['definition']);

            return $info;
        }

        if (($this->canAutowireClass)($id)) {
            return $this->resolutionInfo($id, true, 'autowire');
        }

        if ($this->wrappedContainer !== null && $this->wrappedContainer->has($id)) {
            return $this->resolutionInfo($id, true, 'wrapped');
        }

        return $this->resolutionInfo($id, false, 'not-found');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function trace(string $id): array
    {
        $trace   = [];
        $visited = [];

        while (true) {
            $trace[] = $this->why($id);

            if (isset($visited[$id])) {
                $trace[] = [
                    'id'         => $id,
                    'resolvable' => false,
                    'source'     => 'cycle',
                    'lazy'       => false,
                    'decorators' => 0,
                ];

                break;
            }

            $visited[$id] = true;
            $wildcard     = ($this->matchWildcardDefinition)($id);
            $definition   = $this->definitions[$id] ?? ($wildcard['definition'] ?? null);
            if (!$definition instanceof AliasDefinition) {
                break;
            }

            $id = $definition->targetId();
        }

        return $trace;
    }

    /**
     * @return array<string, mixed>
     */
    public function graph(string $id): array
    {
        return $this->graphNode($id, []);
    }

    public function renderGraph(string $id): string
    {
        return new DiagnosticsReportFormatter()->graph($this->graph($id));
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
        $analyzer = new AotEligibilityAnalyzer(
            definitions: $this->definitions,
            decorators: $this->decorators,
            lazyEntries: $this->lazyEntries,
            autowiring: $this->autowiring,
            attributes: $this->attributes,
        );

        $wildcard = null;
        if (!array_key_exists($id, $this->definitions)) {
            $wildcard = ($this->matchWildcardDefinition)($id);
        }

        if ($wildcard !== null) {
            $report            = $analyzer->analyze($wildcard['pattern'], $wildcard['definition']);
            $report['id']      = $id;
            $report['pattern'] = $wildcard['pattern'];

            return $report;
        }

        return $analyzer->analyze($id);
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
        return new AotCompilationPlan(
            definitions: $this->definitions,
            decorators: $this->decorators,
            lazyEntries: $this->lazyEntries,
            autowiring: $this->autowiring,
            attributes: $this->attributes,
        )->entries();
    }

    public function renderAotPlan(): string
    {
        return new DiagnosticsReportFormatter()->aotPlan($this->aotPlan());
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
        return new ContainerValidator(
            defaultIds: $this->defaultValidationIds(),
            graph: fn (string $id): array => $this->graph($id),
        )->validate($ids);
    }

    /**
     * @param list<string> $ids
     */
    public function renderValidation(array $ids = []): string
    {
        return new DiagnosticsReportFormatter()->validation($this->validate($ids));
    }

    /**
     * @return array{
     *     id: string,
     *     resolvable: bool,
     *     source: string,
     *     lazy: bool,
     *     decorators: int,
     * }
     */
    private function resolutionInfo(string $id, bool $resolvable, string $source): array
    {
        return [
            'id'         => $id,
            'resolvable' => $resolvable,
            'source'     => $source,
            'lazy'       => array_key_exists($id, $this->lazyEntries) || ($this->isImplicitLazyEntry)($id),
            'decorators' => count($this->decorators[$id] ?? []),
        ];
    }

    /**
     * @param array<string, mixed> $info
     */
    private function appendDefinitionDiagnostics(array &$info, DefinitionInterface $definition): void
    {
        $info['definition'] = $definition::class;

        if ($definition instanceof AliasDefinition) {
            $info['target'] = $definition->targetId();
        }

        if ($definition instanceof ObjectDefinition) {
            $info['target'] = $definition->className((string) $info['id']);
        }
    }

    /**
     * @return list<string>
     */
    private function defaultValidationIds(): array
    {
        return array_values(array_unique([
            ...array_keys($this->definitions),
            ...array_keys($this->decorators),
            ...array_keys($this->lazyEntries),
        ]));
    }

    /**
     * @param array<string, bool> $visited
     *
     * @return array<string, mixed>
     */
    private function graphNode(string $id, array $visited): array
    {
        $info                 = $this->why($id);
        $info['dependencies'] = [];

        if (isset($visited[$id])) {
            $info['cycle'] = true;

            return $info;
        }

        if (!$info['resolvable']) {
            return $info;
        }

        $visited[$id] = true;
        $wildcard     = ($this->matchWildcardDefinition)($id);
        $definition   = $this->definitions[$id] ?? ($wildcard['definition'] ?? null);

        if ($definition instanceof AliasDefinition) {
            $info['dependencies'][] = $this->entryDependency('alias', $definition->targetId(), $visited);
        } elseif ($definition instanceof ObjectDefinition) {
            $this->appendObjectDependencies($info, $id, $definition, $visited);
        } elseif ($definition instanceof StringDefinition) {
            $this->appendStringDependencies($info, 'string', $definition->value(), $visited);
        } elseif ($definition instanceof FactoryDefinition) {
            $info['dependencies'][] = [
                'relation' => 'factory',
                'source'   => 'dynamic',
                'reason'   => 'Factory callable is resolved at runtime.',
            ];
        } elseif ($definition instanceof ValueDefinition) {
            $this->appendValueDependencies($info, 'value', $definition->value(), $visited);
        } elseif ($info['source'] === 'autowire') {
            $this->appendObjectDependencies($info, $id, new ObjectDefinition($id), $visited);
        }

        foreach ($this->decorators[$id] ?? [] as $index => $_) {
            $info['dependencies'][] = [
                'relation' => 'decorator',
                'source'   => 'dynamic',
                'index'    => $index,
                'reason'   => 'Decorator callable is resolved at runtime.',
            ];
        }

        return $info;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, bool> $visited
     */
    private function appendObjectDependencies(
        array &$node,
        string $id,
        ObjectDefinition $definition,
        array $visited,
    ): void {
        $className = $definition->className($id);
        if (!is_string($node['target'] ?? null)) {
            $node['target'] = $className;
        }

        if (!class_exists($className)) {
            $node['issues'][] = [
                'source' => 'reflection',
                'reason' => 'class-not-found',
            ];

            return;
        }

        try {
            $reflection = new ReflectionClass($className);
        } catch (ReflectionException) {
            $node['issues'][] = [
                'source' => 'reflection',
                'reason' => 'class-reflection-failed',
            ];

            return;
        }

        if (!$reflection->isInstantiable()) {
            $node['issues'][] = [
                'source' => 'reflection',
                'reason' => 'class-not-instantiable',
            ];

            return;
        }

        $this->appendConstructorDependencies($node, $reflection, $definition->constructorParameters(), $visited);
        $this->appendConfiguredMethodDependencies($node, $reflection, $definition->methodCalls(), $visited);
        $this->appendConfiguredPropertyDependencies($node, $definition->propertyInjections(), $visited);

        if ($this->attributes) {
            $this->appendAttributeDependencies($node, $reflection, $visited);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param ReflectionClass<object> $reflection
     * @param array<string|int, mixed> $definitionParameters
     * @param array<string, bool> $visited
     */
    private function appendConstructorDependencies(
        array &$node,
        ReflectionClass $reflection,
        array $definitionParameters,
        array $visited,
    ): void {
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return;
        }

        [$named, $positional] = $this->splitParameters($definitionParameters);

        foreach ($constructor->getParameters() as $index => $parameter) {
            $metadata = [
                'parameter' => $parameter->getName(),
                'index'     => $index,
                'class'     => $reflection->getName(),
            ];

            if (array_key_exists($parameter->getName(), $named)) {
                $this->appendValueDependencies($node, 'constructor', $named[$parameter->getName()], $visited, $metadata);
                continue;
            }

            if (array_key_exists($index, $positional)) {
                $this->appendValueDependencies($node, 'constructor', $positional[$index], $visited, $metadata);
                continue;
            }

            $this->appendParameterFallbackDependency($node, 'constructor', $parameter, $index, $visited, $metadata);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param ReflectionClass<object> $reflection
     * @param list<array{method: string, parameters: array<string|int, mixed>}> $methodCalls
     * @param array<string, bool> $visited
     */
    private function appendConfiguredMethodDependencies(
        array &$node,
        ReflectionClass $reflection,
        array $methodCalls,
        array $visited,
    ): void {
        foreach ($methodCalls as $call) {
            $methodName = $call['method'];
            $parameters = $call['parameters'] ?? [];

            if (!$reflection->hasMethod($methodName)) {
                $node['issues'][] = [
                    'source' => 'method',
                    'method' => $methodName,
                    'reason' => 'method-not-found',
                ];

                continue;
            }

            $method               = $reflection->getMethod($methodName);
            [$named, $positional] = $this->splitParameters($parameters);

            foreach ($method->getParameters() as $index => $parameter) {
                $metadata = [
                    'method'    => $methodName,
                    'parameter' => $parameter->getName(),
                    'index'     => $index,
                    'class'     => $reflection->getName(),
                ];

                if (array_key_exists($parameter->getName(), $named)) {
                    $this->appendValueDependencies($node, 'method', $named[$parameter->getName()], $visited, $metadata);
                    continue;
                }

                if (array_key_exists($index, $positional)) {
                    $this->appendValueDependencies($node, 'method', $positional[$index], $visited, $metadata);
                    continue;
                }

                $this->appendParameterFallbackDependency($node, 'method', $parameter, $index, $visited, $metadata);
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $propertyInjections
     * @param array<string, bool> $visited
     */
    private function appendConfiguredPropertyDependencies(array &$node, array $propertyInjections, array $visited): void
    {
        foreach ($propertyInjections as $property => $value) {
            $this->appendValueDependencies(
                $node,
                'property',
                $value,
                $visited,
                ['property' => $property],
            );
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param ReflectionClass<object> $reflection
     * @param array<string, bool> $visited
     */
    private function appendAttributeDependencies(array &$node, ReflectionClass $reflection, array $visited): void
    {
        foreach ($reflection->getProperties() as $property) {
            $attributes = $property->getAttributes(Inject::class, ReflectionAttribute::IS_INSTANCEOF);
            if ($attributes === []) {
                continue;
            }

            /** @var Inject $inject */
            $inject = $attributes[0]->newInstance();
            $id     = $this->propertyInjectDependencyId($property, $inject);
            if ($id === null) {
                $node['dependencies'][] = [
                    'relation' => 'attribute-property',
                    'property' => $property->getName(),
                    'class'    => $reflection->getName(),
                    'source'   => 'unresolved',
                ];

                continue;
            }

            $node['dependencies'][] = $this->entryDependency(
                'attribute-property',
                $id,
                $visited,
                [
                    'property' => $property->getName(),
                    'class'    => $reflection->getName(),
                    'source'   => 'attribute',
                ],
            );
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
            $inject = $attributes[0]->newInstance();
            foreach ($method->getParameters() as $index => $parameter) {
                $id = $this->methodInjectDependencyId($parameter, $inject, $index);
                if ($id !== null) {
                    $node['dependencies'][] = $this->entryDependency(
                        'attribute-method',
                        $id,
                        $visited,
                        [
                            'method'    => $method->getName(),
                            'parameter' => $parameter->getName(),
                            'index'     => $index,
                            'class'     => $reflection->getName(),
                            'source'    => 'attribute',
                        ],
                    );

                    continue;
                }

                $this->appendParameterFallbackDependency(
                    $node,
                    'attribute-method',
                    $parameter,
                    $index,
                    $visited,
                    [
                        'method' => $method->getName(),
                        'class'  => $reflection->getName(),
                        'source' => 'attribute',
                    ],
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, bool> $visited
     * @param array<string, mixed> $metadata
     */
    private function appendParameterFallbackDependency(
        array &$node,
        string $relation,
        ReflectionParameter $parameter,
        int $index,
        array $visited,
        array $metadata = [],
    ): void {
        if ($parameter->isVariadic()) {
            return;
        }

        $id = $this->parameterInjectDependencyId($parameter, $index);
        if ($id !== null) {
            $node['dependencies'][] = $this->entryDependency($relation, $id, $visited, [
                ...$metadata,
                'source' => 'attribute',
            ]);

            return;
        }

        $typeIds = $this->typeDependencyIds($parameter->getType());
        if ($typeIds !== []) {
            foreach ($typeIds as $typeId) {
                $node['dependencies'][] = $this->entryDependency($relation, $typeId, $visited, [
                    ...$metadata,
                    'source' => 'type-hint',
                ]);
            }

            return;
        }

        if (!$parameter->isDefaultValueAvailable() && !$this->parameterAllowsNull($parameter)) {
            $node['dependencies'][] = [
                ...$metadata,
                'relation' => $relation,
                'source'   => 'unresolved',
                'reason'   => 'required-parameter',
            ];
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, bool> $visited
     * @param array<string, mixed> $metadata
     */
    private function appendValueDependencies(
        array &$node,
        string $relation,
        mixed $value,
        array $visited,
        array $metadata = [],
    ): void {
        if ($value instanceof DefinitionHelperInterface) {
            $value = $value->toDefinition('__diagnostics_inline__');
        }

        if ($value instanceof AliasDefinition) {
            $node['dependencies'][] = $this->entryDependency($relation, $value->targetId(), $visited, [
                ...$metadata,
                'source' => 'definition',
            ]);

            return;
        }

        if ($value instanceof ValueDefinition) {
            $this->appendValueDependencies($node, $relation, $value->value(), $visited, $metadata);

            return;
        }

        if ($value instanceof StringDefinition) {
            $this->appendStringDependencies($node, $relation, $value->value(), $visited, $metadata);

            return;
        }

        if ($value instanceof FactoryDefinition) {
            $node['dependencies'][] = [
                ...$metadata,
                'relation' => $relation,
                'source'   => 'dynamic',
                'reason'   => 'Factory callable is resolved at runtime.',
            ];

            return;
        }

        if (!is_array($value)) {
            return;
        }

        foreach ($value as $key => $item) {
            $this->appendValueDependencies($node, $relation, $item, $visited, [
                ...$metadata,
                'path' => ($metadata['path'] ?? '') . '[' . (string) $key . ']',
            ]);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, bool> $visited
     * @param array<string, mixed> $metadata
     */
    private function appendStringDependencies(
        array &$node,
        string $relation,
        string $value,
        array $visited,
        array $metadata = [],
    ): void {
        $matchCount = preg_match_all('/\{([^{}]+)\}/', $value, $matches);
        if ($matchCount === false || $matchCount === 0) {
            return;
        }

        foreach ($matches[1] as $expression) {
            $parts = explode('|', (string) $expression);
            $id    = trim($parts[0]);
            if ($id === '') {
                continue;
            }

            $node['dependencies'][] = $this->entryDependency($relation, $id, $visited, [
                ...$metadata,
                'source' => 'string-interpolation',
            ]);
        }
    }

    /**
     * @param array<string, bool> $visited
     * @param array<string, mixed> $metadata
     *
     * @return array<string, mixed>
     */
    private function entryDependency(string $relation, string $id, array $visited, array $metadata = []): array
    {
        return [
            ...$metadata,
            'relation'   => $relation,
            'id'         => $id,
            'resolvable' => $this->canResolve($id),
            'node'       => $this->graphNode($id, $visited),
        ];
    }

    private function parameterInjectDependencyId(ReflectionParameter $parameter, int $index): ?string
    {
        if (!$this->attributes) {
            return null;
        }

        $attributes = $parameter->getAttributes(Inject::class, ReflectionAttribute::IS_INSTANCEOF);
        if ($attributes === []) {
            return null;
        }

        /** @var Inject $inject */
        $inject = $attributes[0]->newInstance();
        if (is_string($inject->name)) {
            return $inject->name;
        }

        if (is_array($inject->name)) {
            if (array_key_exists($parameter->getName(), $inject->name)) {
                return $inject->name[$parameter->getName()];
            }

            if (array_key_exists($index, $inject->name)) {
                return $inject->name[$index];
            }
        }

        return $this->firstTypeDependencyId($parameter->getType());
    }

    private function propertyInjectDependencyId(ReflectionProperty $property, Inject $inject): ?string
    {
        if (is_string($inject->name)) {
            return $inject->name;
        }

        return $this->firstTypeDependencyId($property->getType());
    }

    private function methodInjectDependencyId(ReflectionParameter $parameter, Inject $inject, int $index): ?string
    {
        if (is_array($inject->name)) {
            if (array_key_exists($parameter->getName(), $inject->name)) {
                return $inject->name[$parameter->getName()];
            }

            if (array_key_exists($index, $inject->name)) {
                return $inject->name[$index];
            }
        }

        if (is_string($inject->name) && $index === 0) {
            return $inject->name;
        }

        return $this->parameterInjectDependencyId($parameter, $index);
    }

    private function firstTypeDependencyId(?ReflectionType $type): ?string
    {
        $ids = $this->typeDependencyIds($type);

        return $ids[0] ?? null;
    }

    /**
     * @return list<string>
     */
    private function typeDependencyIds(?ReflectionType $type): array
    {
        if ($type instanceof ReflectionNamedType) {
            if ($type->isBuiltin() || $type->getName() === RequestedEntry::class) {
                return [];
            }

            return $this->canResolve($type->getName()) ? [$type->getName()] : [];
        }

        if ($type instanceof ReflectionUnionType) {
            $ids = [];
            foreach ($type->getTypes() as $nestedType) {
                if ($nestedType instanceof ReflectionNamedType
                    && !$nestedType->isBuiltin()
                    && $nestedType->getName() !== RequestedEntry::class
                    && $this->canResolve($nestedType->getName())
                ) {
                    $ids[] = $nestedType->getName();
                }
            }

            return $ids;
        }

        if ($type instanceof ReflectionIntersectionType) {
            foreach ($type->getTypes() as $nestedType) {
                if ($nestedType instanceof ReflectionNamedType
                    && !$nestedType->isBuiltin()
                    && $nestedType->getName() !== RequestedEntry::class
                    && $this->canResolve($nestedType->getName())
                ) {
                    return [$nestedType->getName()];
                }
            }
        }

        return [];
    }

    private function parameterAllowsNull(ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();
        if (!$type instanceof ReflectionType) {
            return true;
        }

        return $type->allowsNull();
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
