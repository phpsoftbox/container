<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Compilation;

use PhpSoftBox\Container\Attribute\Inject;
use PhpSoftBox\Container\Container;
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
use function hash;
use function implode;
use function is_array;
use function is_bool;
use function is_callable;
use function is_float;
use function is_int;
use function is_null;
use function is_string;
use function ltrim;
use function str_contains;
use function substr;
use function var_export;

final readonly class AotContainerClassGenerator
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

    public function render(string $namespace, string $className): string
    {
        $compiledEntries = $this->compileEntries();
        $parentClass     = '\\' . Container::class;

        $getCases  = [];
        $makeCases = [];
        $methods   = [];
        $metadata  = [];

        foreach ($compiledEntries as $id => $entry) {
            $idExport      = var_export($id, true);
            $method        = $entry['method'];
            $metadata[$id] = $entry['kind'];

            $getCases[] = <<<PHP
                case {$idExport}:
                    if (\\array_key_exists({$idExport}, \$this->__aotResolved)) {
                        return \$this->__aotResolved[{$idExport}];
                    }

                    return \$this->__aotResolved[{$idExport}] = \$this->resolveCompiledEntry({$idExport}, fn (): mixed => \$this->{$method}());
PHP;

            $makeCases[] = <<<PHP
                case {$idExport}:
                    return \$this->resolveCompiledEntry({$idExport}, fn (): mixed => \$this->{$method}());
PHP;

            $methods[] = $entry['code'];
        }

        $getCasesCode  = $getCases === [] ? '' : "\n" . implode("\n", $getCases) . "\n";
        $makeCasesCode = $makeCases === [] ? '' : "\n" . implode("\n", $makeCases) . "\n";
        $methodsCode   = $methods === [] ? '' : "\n\n" . implode("\n\n", $methods);
        $metadataCode  = var_export($metadata, true);

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

final class {$className} extends {$parentClass}
{
    private array \$__aotResolved = [];
    private array \$__aotInvalidated = [];

    public function get(string \$id): mixed
    {
        if (isset(\$this->__aotInvalidated[\$id])) {
            return parent::get(\$id);
        }

        switch (\$id) {{$getCasesCode}        }

        return parent::get(\$id);
    }

    public function make(string \$id, array \$parameters = []): mixed
    {
        if (\$parameters !== [] || isset(\$this->__aotInvalidated[\$id])) {
            return parent::make(\$id, \$parameters);
        }

        switch (\$id) {{$makeCasesCode}        }

        return parent::make(\$id, \$parameters);
    }

    public function set(string \$id, mixed \$value): void
    {
        \$this->__aotInvalidated[\$id] = true;
        unset(\$this->__aotResolved[\$id]);

        parent::set(\$id, \$value);
    }

    public function __compiledEntries(): array
    {
        return {$metadataCode};
    }{$methodsCode}
}
PHP;
    }

    /**
     * @return array<string, array{kind: string, method: string, code: string}>
     */
    private function compileEntries(): array
    {
        $entries = [];
        $plan    = new AotCompilationPlan(
            definitions: $this->definitions,
            decorators: $this->decorators,
            lazyEntries: $this->lazyEntries,
            autowiring: $this->autowiring,
            attributes: $this->attributes,
        );

        foreach ($plan->eligibleEntries() as $id => $_entry) {
            $definition = $this->normalizeDefinition($id, $this->definitions[$id]);
            $compiled   = $this->compileDefinition($id, $definition);
            if ($compiled === null) {
                continue;
            }

            $entries[$id] = $compiled;
        }

        return $entries;
    }

    /**
     * @return array{kind: string, method: string, code: string}|null
     */
    private function compileDefinition(string $id, DefinitionInterface $definition): ?array
    {
        if ($definition instanceof ValueDefinition) {
            $expression = $this->compileValueExpression($definition->value());
            if ($expression === null) {
                return null;
            }

            return $this->compiledEntry(
                $id,
                $this->compiledKind($id, 'value'),
                $this->returnExpression($id, $expression),
            );
        }

        if ($definition instanceof StringDefinition) {
            return $this->compiledEntry(
                $id,
                $this->compiledKind($id, 'string'),
                $this->returnExpression(
                    $id,
                    '$this->interpolateString(' . var_export($definition->value(), true) . ')',
                ),
            );
        }

        if ($definition instanceof FactoryDefinition) {
            return $this->compiledEntry(
                $id,
                $this->compiledKind($id, 'factory'),
                $this->returnExpression($id, '$this->resolveFactoryEntry(' . var_export($id, true) . ')'),
            );
        }

        if ($definition instanceof ObjectDefinition) {
            return $this->compileObjectDefinition($id, $definition);
        }

        return null;
    }

    /**
     * @return array{kind: string, method: string, code: string}
     */
    private function compiledEntry(string $id, string $kind, string $body): array
    {
        $method = '__aot_' . substr(hash('sha256', $id), 0, 16);
        $code   = <<<PHP
    private function {$method}(): mixed
    {
        {$body}
    }
PHP;

        return [
            'kind'   => $kind,
            'method' => $method,
            'code'   => $code,
        ];
    }

    /**
     * @return array{kind: string, method: string, code: string}|null
     */
    private function compileObjectDefinition(string $id, ObjectDefinition $definition): ?array
    {
        $className = $definition->className($id);
        if (!class_exists($className)) {
            return null;
        }

        try {
            $reflection = new ReflectionClass($className);
        } catch (ReflectionException) {
            return null;
        }

        if (!$reflection->isInstantiable()) {
            return null;
        }

        $arguments = $this->compileConstructorArguments($reflection, $definition->constructorParameters());
        if ($arguments === null) {
            return null;
        }

        $classExpression = '\\' . ltrim($className, '\\');
        $createObject    = 'new ' . $classExpression . '(' . implode(', ', $arguments) . ')';

        $methodCalls = $this->compileMethodCallsExpression($definition->methodCalls());
        if ($methodCalls === null) {
            return null;
        }

        $propertyInjections = $this->compileArrayExpression($definition->propertyInjections());
        if ($propertyInjections === null) {
            return null;
        }

        $needsInjection = $this->attributes
            || $definition->methodCalls() !== []
            || $definition->propertyInjections() !== [];

        if (!$needsInjection) {
            return $this->compiledEntry(
                $id,
                $this->compiledKind($id, 'object'),
                $this->returnExpression($id, $createObject),
            );
        }

        $body = "\$object = {$createObject};\n\n"
            . "        \$object = \$this->injectObject(\$object, {$methodCalls}, {$propertyInjections});\n\n"
            . '        ' . $this->returnExpression($id, '$object');

        return $this->compiledEntry(
            $id,
            $this->compiledKind($id, 'object'),
            $body,
        );
    }

    private function compiledKind(string $id, string $kind): string
    {
        return $this->hasDecorators($id) ? 'decorated-' . $kind : $kind;
    }

    private function returnExpression(string $id, string $expression): string
    {
        if (!$this->hasDecorators($id)) {
            return 'return ' . $expression . ';';
        }

        return 'return $this->applyDecorators(' . var_export($id, true) . ', ' . $expression . ');';
    }

    private function hasDecorators(string $id): bool
    {
        return array_key_exists($id, $this->decorators) && $this->decorators[$id] !== [];
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @param array<string|int, mixed> $definitionParameters
     *
     * @return list<string>|null
     */
    private function compileConstructorArguments(ReflectionClass $reflection, array $definitionParameters): ?array
    {
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return [];
        }

        [$named, $positional] = $this->splitParameters($definitionParameters);
        $arguments            = [];

        foreach ($constructor->getParameters() as $index => $parameter) {
            if ($parameter->isVariadic()) {
                return null;
            }

            $name = $parameter->getName();
            if (array_key_exists($name, $named)) {
                $expression = $this->compileValueExpression($named[$name]);
                if ($expression === null) {
                    return null;
                }

                $arguments[] = $expression;
                continue;
            }

            if (array_key_exists($index, $positional)) {
                $expression = $this->compileValueExpression($positional[$index]);
                if ($expression === null) {
                    return null;
                }

                $arguments[] = $expression;
                continue;
            }

            $expression = $this->compileParameterFallbackExpression($parameter, $index);
            if ($expression === null) {
                return null;
            }

            $arguments[] = $expression;
        }

        return $arguments;
    }

    private function compileParameterFallbackExpression(ReflectionParameter $parameter, int $index): ?string
    {
        $attributeExpression = $this->compileInjectAttributeExpression($parameter, $index);
        if ($attributeExpression !== null) {
            return $attributeExpression;
        }

        $type = $parameter->getType();
        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $className = $type->getName();
            if (array_key_exists($className, $this->definitions) || ($this->autowiring && class_exists($className))) {
                return '$this->get(' . var_export($className, true) . ')';
            }
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $this->compileValueExpression($parameter->getDefaultValue());
        }

        if ($this->parameterAllowsNull($parameter)) {
            return 'null';
        }

        return null;
    }

    private function compileInjectAttributeExpression(ReflectionParameter $parameter, int $index): ?string
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
            return '$this->get(' . var_export($inject->name, true) . ')';
        }

        if (is_array($inject->name)) {
            if (array_key_exists($parameter->getName(), $inject->name)) {
                return '$this->get(' . var_export((string) $inject->name[$parameter->getName()], true) . ')';
            }

            if (array_key_exists($index, $inject->name)) {
                return '$this->get(' . var_export((string) $inject->name[$index], true) . ')';
            }

            if (array_key_exists(0, $inject->name)) {
                return '$this->get(' . var_export((string) $inject->name[0], true) . ')';
            }
        }

        return null;
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
     * @param list<array{method: string, parameters: array<string|int, mixed>}> $methodCalls
     */
    private function compileMethodCallsExpression(array $methodCalls): ?string
    {
        $items = [];

        foreach ($methodCalls as $call) {
            $parameters = $this->compileArrayExpression($call['parameters'] ?? []);
            if ($parameters === null) {
                return null;
            }

            $items[] = '['
                . var_export('method', true) . ' => ' . var_export($call['method'], true) . ', '
                . var_export('parameters', true) . ' => ' . $parameters
                . ']';
        }

        return '[' . implode(', ', $items) . ']';
    }

    private function compileValueExpression(mixed $value): ?string
    {
        if (is_null($value) || is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
            return var_export($value, true);
        }

        if (is_array($value)) {
            return $this->compileArrayExpression($value);
        }

        if ($value instanceof DefinitionHelperInterface) {
            return $this->compileValueExpression($value->toDefinition('__aot_inline__'));
        }

        if ($value instanceof AliasDefinition) {
            return '$this->get(' . var_export($value->targetId(), true) . ')';
        }

        if ($value instanceof ValueDefinition) {
            return $this->compileValueExpression($value->value());
        }

        if ($value instanceof StringDefinition) {
            return '$this->interpolateString(' . var_export($value->value(), true) . ')';
        }

        return null;
    }

    /**
     * @param array<int|string, mixed> $value
     */
    private function compileArrayExpression(array $value): ?string
    {
        $items = [];

        foreach ($value as $key => $item) {
            $expression = $this->compileValueExpression($item);
            if ($expression === null) {
                return null;
            }

            $items[] = var_export($key, true) . ' => ' . $expression;
        }

        return '[' . implode(', ', $items) . ']';
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
