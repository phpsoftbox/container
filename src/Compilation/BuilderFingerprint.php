<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Compilation;

use Closure;
use PhpSoftBox\Container\Definition\AddDefinition;
use PhpSoftBox\Container\Definition\AliasDefinition;
use PhpSoftBox\Container\Definition\DecoratorDefinition;
use PhpSoftBox\Container\Definition\EnvDefinition;
use PhpSoftBox\Container\Definition\FactoryDefinition;
use PhpSoftBox\Container\Definition\Helper\DecoratorHelperInterface;
use PhpSoftBox\Container\Definition\Helper\DefinitionHelperInterface;
use PhpSoftBox\Container\Definition\Helper\LazyEntryHelper;
use PhpSoftBox\Container\Definition\ObjectDefinition;
use PhpSoftBox\Container\Definition\StringDefinition;
use PhpSoftBox\Container\Definition\ValueDefinition;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use Throwable;
use UnitEnum;

use function array_is_list;
use function array_key_exists;
use function class_exists;
use function filemtime;
use function filesize;
use function get_debug_type;
use function hash;
use function interface_exists;
use function is_array;
use function is_bool;
use function is_callable;
use function is_float;
use function is_int;
use function is_object;
use function is_string;
use function ksort;
use function serialize;
use function sprintf;
use function str_contains;
use function str_replace;

use const PHP_VERSION_ID;

final class BuilderFingerprint
{
    /**
     * @param array<string, mixed> $definitions
     * @param array<string, list<mixed>> $decorators
     * @param array<string, string|null> $lazyEntries
     * @param array<string, bool> $definitionFiles
     */
    public function create(
        bool $autowiring,
        bool $attributes,
        array $definitions,
        array $decorators,
        array $lazyEntries,
        array $definitionFiles,
        ?string $wrappedContainerClass = null,
    ): string {
        ksort($definitions);
        ksort($decorators);
        ksort($lazyEntries);
        ksort($definitionFiles);

        $files = [];
        foreach ($definitionFiles as $file => $_) {
            $files[$file] = [
                'mtime' => @filemtime($file) ?: null,
                'size'  => @filesize($file) ?: null,
            ];
        }

        $payload = [
            'schema'            => CompiledCacheStorage::SCHEMA,
            'php_version_id'    => PHP_VERSION_ID,
            'autowiring'        => $autowiring,
            'attributes'        => $attributes,
            'wrapped_container' => $wrappedContainerClass,
            'definitions'       => $this->normalizeForSignature($definitions),
            'decorators'        => $this->normalizeForSignature($decorators),
            'lazy_entries'      => $this->normalizeForSignature($lazyEntries),
            'definition_files'  => $files,
            // AOT-генератор вшивает в класс контейнера аргументы конструкторов (порядок, типы, значения
            // по умолчанию, #[Inject]). Их изменение (например, после composer update) должно менять
            // fingerprint, иначе будет подключён устаревший скомпилированный класс.
            'constructors' => $this->constructorSignatures($definitions),
        ];

        return hash('sha256', serialize($payload));
    }

    /**
     * @return array<string, mixed>|list<mixed>|bool|int|float|string|null
     */
    private function normalizeForSignature(mixed $value): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                $list = [];
                foreach ($value as $item) {
                    $list[] = $this->normalizeForSignature($item);
                }

                return $list;
            }

            ksort($value);
            $map = [];
            foreach ($value as $key => $item) {
                $map[(string) $key] = $this->normalizeForSignature($item);
            }

            return $map;
        }

        if ($value instanceof LazyEntryHelper) {
            return [
                'type'   => 'lazy-helper',
                'target' => $value->targetId('__signature__'),
                'class'  => $value->className(),
            ];
        }

        if ($value instanceof AliasDefinition) {
            return ['type' => 'alias-definition', 'target' => $value->targetId()];
        }

        if ($value instanceof ObjectDefinition) {
            return [
                'type'        => 'object-definition',
                'class'       => $value->className('__signature__'),
                'constructor' => $this->normalizeForSignature($value->constructorParameters()),
                'methods'     => $this->normalizeForSignature($value->methodCalls()),
                'properties'  => $this->normalizeForSignature($value->propertyInjections()),
            ];
        }

        if ($value instanceof FactoryDefinition) {
            return ['type' => 'factory-definition', 'factory' => $this->normalizeForSignature($value->factory())];
        }

        if ($value instanceof AddDefinition) {
            return [
                'type'     => 'add-definition',
                'items'    => $this->normalizeForSignature($value->items()),
                'strategy' => $value->strategy(),
            ];
        }

        if ($value instanceof EnvDefinition) {
            return [
                'type'    => 'env-definition',
                'name'    => $value->name(),
                'default' => $this->normalizeForSignature($value->default()),
            ];
        }

        if ($value instanceof StringDefinition) {
            return ['type' => 'string-definition', 'value' => $value->value()];
        }

        if ($value instanceof ValueDefinition) {
            return ['type' => 'value-definition', 'value' => $this->normalizeForSignature($value->value())];
        }

        if ($value instanceof DecoratorDefinition) {
            return ['type' => 'decorator-definition', 'decorator' => $this->normalizeForSignature($value->decorator())];
        }

        if ($value instanceof DefinitionHelperInterface) {
            return [
                'type'       => 'definition-helper',
                'class'      => $value::class,
                'definition' => $this->normalizeForSignature($value->toDefinition('__signature__')),
            ];
        }

        if ($value instanceof DecoratorHelperInterface) {
            return [
                'type'      => 'decorator-helper',
                'class'     => $value::class,
                'decorator' => $this->normalizeForSignature($value->toDecorator('__signature__')),
            ];
        }

        if ($value instanceof Closure) {
            $reflection = new ReflectionFunction($value);

            $file = $reflection->getFileName();

            return [
                'type'  => 'closure',
                'file'  => $file,
                'start' => $reflection->getStartLine(),
                'end'   => $reflection->getEndLine(),
                'mtime' => $file !== false ? @filemtime($file) : null,
            ];
        }

        if (is_callable($value)) {
            return [
                'type'      => 'callable',
                'signature' => $this->callableSignature($value),
            ];
        }

        if ($value instanceof UnitEnum) {
            return ['type' => 'enum', 'class' => $value::class, 'case' => $value->name];
        }

        if (is_object($value)) {
            return ['type' => 'object', 'class' => $value::class];
        }

        return ['type' => 'unknown', 'debug' => get_debug_type($value)];
    }

    /**
     * @param array<string, mixed> $definitions
     *
     * @return array<string, mixed>
     */
    private function constructorSignatures(array $definitions): array
    {
        $signatures = [];

        foreach ($definitions as $id => $definition) {
            $id = (string) $id;

            if ($definition instanceof DefinitionHelperInterface) {
                try {
                    $definition = $definition->toDefinition($id);
                } catch (Throwable) {
                    continue;
                }
            }

            if (!$definition instanceof ObjectDefinition) {
                continue;
            }

            $className = $definition->className($id);
            if (str_contains($className, '*') || array_key_exists($className, $signatures)) {
                continue;
            }

            $signatures[$className] = $this->classSignature($className);
        }

        ksort($signatures);

        return $signatures;
    }

    /**
     * @return array<string, mixed>|string
     */
    private function classSignature(string $className): array|string
    {
        if (!class_exists($className)) {
            return 'missing';
        }

        try {
            $reflection = new ReflectionClass($className);
        } catch (ReflectionException) {
            return 'unreflectable';
        }

        $parameters = [];
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $parameters[] = $this->parameterSignature($parameter);
        }

        return [
            'instantiable' => $reflection->isInstantiable(),
            'parameters'   => $parameters,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parameterSignature(ReflectionParameter $parameter): array
    {
        $type    = $parameter->getType();
        $default = null;

        if ($parameter->isDefaultValueAvailable()) {
            try {
                $default = $this->normalizeForSignature($parameter->getDefaultValue());
            } catch (Throwable) {
                $default = ['type' => 'unresolved-default'];
            }
        }

        $attributes = [];
        foreach ($parameter->getAttributes() as $attribute) {
            $attributes[] = [
                'name'      => $attribute->getName(),
                'arguments' => $this->normalizeForSignature($attribute->getArguments()),
            ];
        }

        return [
            'name'        => $parameter->getName(),
            'type'        => $type !== null ? (string) $type : null,
            'classes'     => $type !== null ? $this->typeClassStates($type) : [],
            'variadic'    => $parameter->isVariadic(),
            'has_default' => $parameter->isDefaultValueAvailable(),
            'default'     => $default,
            'attributes'  => $attributes,
        ];
    }

    /**
     * Состояние классов из типа параметра: генератор выбирает $this->get() или значение по умолчанию
     * в зависимости от того, существует ли класс и можно ли его создать.
     *
     * @return array<string, string>
     */
    private function typeClassStates(ReflectionType $type): array
    {
        $types = $type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType
            ? $type->getTypes()
            : [$type];
        $states = [];

        foreach ($types as $nestedType) {
            if ($nestedType instanceof ReflectionIntersectionType) {
                $states = [...$states, ...$this->typeClassStates($nestedType)];

                continue;
            }

            if (!$nestedType instanceof ReflectionNamedType || $nestedType->isBuiltin()) {
                continue;
            }

            $name          = $nestedType->getName();
            $states[$name] = match (true) {
                AotTypeInspector::isInstantiableClass($name) => 'instantiable',
                class_exists($name)                          => 'class',
                interface_exists($name)                      => 'interface',
                default                                      => 'missing',
            };
        }

        return $states;
    }

    private function callableSignature(callable $callable): string
    {
        if (is_string($callable)) {
            return 'string:' . $callable;
        }

        if (is_array($callable) && isset($callable[0], $callable[1])) {
            $target = is_object($callable[0]) ? $callable[0]::class : (string) $callable[0];

            return 'array:' . $target . '::' . (string) $callable[1];
        }

        if (is_object($callable) && !($callable instanceof Closure)) {
            return 'object:' . $callable::class . '::__invoke';
        }

        if ($callable instanceof Closure) {
            $reflection = new ReflectionFunction($callable);

            $file = (string) $reflection->getFileName();

            return sprintf(
                'closure:%s:%d-%d:%d',
                str_replace('\\', '/', $file),
                $reflection->getStartLine(),
                $reflection->getEndLine(),
                $file !== '' ? ((int) @filemtime($file)) : 0,
            );
        }

        return get_debug_type($callable);
    }
}
