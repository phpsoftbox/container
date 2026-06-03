<?php

declare(strict_types=1);

namespace PhpSoftBox\Container;

use PhpSoftBox\Container\Compilation\AotContainerClassGenerator;
use PhpSoftBox\Container\Compilation\BuilderFingerprint;
use PhpSoftBox\Container\Compilation\CompiledCacheStorage;
use PhpSoftBox\Container\Compilation\DefinitionCacheInterface;
use PhpSoftBox\Container\Compilation\LazyEntryCompiler;
use PhpSoftBox\Container\Definition\AddDefinition;
use PhpSoftBox\Container\Definition\AliasDefinition;
use PhpSoftBox\Container\Definition\DecoratorDefinition;
use PhpSoftBox\Container\Definition\DefinitionInterface;
use PhpSoftBox\Container\Definition\Helper\AddDefinitionHelper;
use PhpSoftBox\Container\Definition\Helper\DecoratorHelperInterface;
use PhpSoftBox\Container\Definition\Helper\DefinitionHelperInterface;
use PhpSoftBox\Container\Definition\Helper\LazyEntryHelper;
use PhpSoftBox\Container\Definition\ValueDefinition;
use PhpSoftBox\Container\Profiler\ContainerProfilerCollector;
use PhpSoftBox\Profiler\ProfilerInterface;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionException;
use RuntimeException;

use function array_key_exists;
use function class_exists;
use function file_exists;
use function file_put_contents;
use function in_array;
use function is_array;
use function is_object;
use function is_string;
use function preg_replace;
use function realpath;
use function rtrim;
use function sha1;

use const LOCK_EX;

final class ContainerBuilder
{
    /**
     * @var array<string, mixed>
     */
    private array $definitions = [];
    /**
     * @var array<string, list<mixed>>
     */
    private array $decorators = [];
    /**
     * @var array<string, string|null>
     */
    private array $lazyEntries = [];
    /**
     * @var array<string, bool>
     */
    private array $definitionFiles = [];

    private bool $autowiring                               = true;
    private bool $attributes                               = false;
    private ?string $compilationDirectory                  = null;
    private ?DefinitionCacheInterface $definitionCache     = null;
    private ?ContainerInterface $wrappedContainer          = null;
    private ?ProfilerInterface $profiler                   = null;
    private ?ContainerProfilerCollector $profilerCollector = null;

    public function __construct(array|string $definitions = [])
    {
        if ($definitions !== []) {
            $this->addDefinitions($definitions);
        }
    }

    public function useAutowiring(bool $enabled): self
    {
        $this->autowiring = $enabled;

        return $this;
    }

    public function useAttributes(bool $enabled): self
    {
        $this->attributes = $enabled;

        return $this;
    }

    public function setDefinitionCache(?DefinitionCacheInterface $cache): self
    {
        $this->definitionCache = $cache;

        return $this;
    }

    public function wrapContainer(ContainerInterface $container): self
    {
        $this->wrappedContainer = $container;

        return $this;
    }

    public function setProfiler(
        ?ProfilerInterface $profiler,
        ?ContainerProfilerCollector $collector = null,
    ): self {
        $this->profiler          = $profiler;
        $this->profilerCollector = $collector;

        return $this;
    }

    public function enableCompilation(string $directory): self
    {
        $directory = rtrim($directory, '/');
        if ($directory === '') {
            throw new RuntimeException('Compilation directory cannot be empty.');
        }

        $this->compilationDirectory = $directory;

        return $this;
    }

    public function invalidateCompilationCache(): self
    {
        $fingerprint = $this->fingerprint();
        $this->definitionCache?->delete('lazy:' . $fingerprint);
        $this->cacheStorage()?->clear();

        return $this;
    }

    public function addDefinitions(array|string $definitions): self
    {
        if (is_string($definitions)) {
            $definitions = $this->loadDefinitionsFromFile($definitions);
        }

        foreach ($definitions as $id => $definition) {
            $this->registerDefinition((string) $id, $definition);
        }

        return $this;
    }

    public function decorate(string $id, mixed $decorator): self
    {
        $this->decorators[$id][] = $decorator;

        return $this;
    }

    public function lazy(string $id, ?string $className = null): self
    {
        $this->lazyEntries[$id] = $className;

        return $this;
    }

    public function build(): Container
    {
        $fingerprint = $this->fingerprint();

        $lazyEntries    = $this->buildLazyEntries($fingerprint);
        $containerClass = $this->resolveContainerClass($fingerprint);

        return new $containerClass(
            definitions: $this->definitions,
            autowiring: $this->autowiring,
            decorators: $this->decorators,
            lazyEntries: $lazyEntries,
            attributes: $this->attributes,
            wrappedContainer: $this->wrappedContainer,
            profiler: $this->profiler,
            profilerCollector: $this->profilerCollector,
        );
    }

    /**
     * @param list<string> $entries
     *
     * @return array{
     *     valid: bool,
     *     checked: list<string>,
     *     issues: list<array<string, mixed>>,
     * }
     */
    public function validate(array $entries = []): array
    {
        return $this->build()->validate($entries);
    }

    private function fingerprint(): string
    {
        return new BuilderFingerprint()->create(
            autowiring: $this->autowiring,
            attributes: $this->attributes,
            definitions: $this->definitions,
            decorators: $this->decorators,
            lazyEntries: $this->lazyEntries,
            definitionFiles: $this->definitionFiles,
            wrappedContainerClass: $this->wrappedContainer !== null ? $this->wrappedContainer::class : null,
        );
    }

    /**
     * Build and pre-resolve selected entries for CI/deploy warmup.
     *
     * @param list<string> $entries
     */
    public function warmup(array $entries = [], bool $initializeLazy = true): Container
    {
        $container = $this->build();

        if ($entries === []) {
            $entries = $this->defaultWarmupEntries();
        }

        foreach ($entries as $entryId) {
            $resolved = $container->get((string) $entryId);

            if ($initializeLazy) {
                $this->initializeLazyObjectIfNeeded($resolved);
            }
        }

        return $container;
    }

    /**
     * @return list<string>
     */
    private function defaultWarmupEntries(): array
    {
        $entries = [];

        foreach ($this->definitions as $id => $_) {
            $entries[] = (string) $id;
        }

        foreach ($this->decorators as $id => $_) {
            if (!in_array($id, $entries, true)) {
                $entries[] = $id;
            }
        }

        foreach ($this->lazyEntries as $id => $_) {
            if (!in_array($id, $entries, true)) {
                $entries[] = $id;
            }
        }

        return $entries;
    }

    private function initializeLazyObjectIfNeeded(mixed $value): void
    {
        if (!is_object($value)) {
            return;
        }

        try {
            $reflection = new ReflectionClass($value);
        } catch (ReflectionException) {
            return;
        }

        if (!$reflection->isUninitializedLazyObject($value)) {
            return;
        }

        $reflection->initializeLazyObject($value);
    }

    /**
     */
    private function loadDefinitionsFromFile(string $definitionsFile): array
    {
        $resolvedPath = realpath($definitionsFile);
        $path         = $resolvedPath !== false ? $resolvedPath : $definitionsFile;

        if (!file_exists($path)) {
            throw new RuntimeException('Definitions file not found: ' . $definitionsFile);
        }

        $definitions = require $path;
        if (!is_array($definitions)) {
            throw new RuntimeException('Definitions file must return array: ' . $definitionsFile);
        }

        $this->definitionFiles[$path] = true;

        return $definitions;
    }

    private function registerDefinition(string $id, mixed $definition): void
    {
        if ($definition instanceof DecoratorDefinition || $definition instanceof DecoratorHelperInterface) {
            $this->decorators[$id][] = $definition;

            return;
        }

        if ($definition instanceof LazyEntryHelper) {
            $targetId                     = $definition->targetId($id);
            $this->lazyEntries[$targetId] = $definition->className();

            if ($targetId !== $id) {
                $this->definitions[$id] = new AliasDefinition($targetId);
            }

            return;
        }

        if ($definition instanceof AddDefinitionHelper || $definition instanceof AddDefinition) {
            $this->mergeArrayDefinition($id, $definition);

            return;
        }

        $this->definitions[$id] = $definition;
    }

    private function mergeArrayDefinition(string $id, AddDefinitionHelper|AddDefinition $definition): void
    {
        $items = $definition->items();

        if (!array_key_exists($id, $this->definitions)) {
            $this->definitions[$id] = $items;

            return;
        }

        $strategy               = $definition->strategy();
        $base                   = $this->extractArrayDefinitionValue($id, $this->definitions[$id]);
        $this->definitions[$id] = AddDefinition::merge($base, $items, $strategy);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function extractArrayDefinitionValue(string $id, mixed $definition): array
    {
        if (is_array($definition)) {
            return $definition;
        }

        if ($definition instanceof DefinitionHelperInterface) {
            $definition = $definition->toDefinition($id);
        }

        if ($definition instanceof ValueDefinition && is_array($definition->value())) {
            return $definition->value();
        }

        if ($definition instanceof DefinitionInterface) {
            throw new RuntimeException('add() can be applied only to array definitions: ' . $id);
        }

        throw new RuntimeException('add() can be applied only to array definitions: ' . $id);
    }

    /**
     * @return array<string, string>
     */
    private function buildLazyEntries(string $fingerprint): array
    {
        $cacheKey = 'lazy:' . $fingerprint;
        if ($this->definitionCache !== null && $this->definitionCache->has($cacheKey)) {
            $cached = $this->definitionCache->get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $cacheStorage = $this->cacheStorage();
        if ($cacheStorage !== null) {
            $cacheStorage->ensureDirectory();
            $cached = $cacheStorage->read($fingerprint);
            if ($cached !== null) {
                if ($this->definitionCache !== null) {
                    $this->definitionCache->set($cacheKey, $cached);
                }

                return $cached;
            }
        }

        [$resolvedLazyEntries, $lazyClassMetadata] = new LazyEntryCompiler(
            definitions: $this->definitions,
            lazyEntries: $this->lazyEntries,
        )->compile();

        if ($cacheStorage !== null) {
            $cacheStorage->write($fingerprint, $resolvedLazyEntries, $lazyClassMetadata);
        }

        if ($this->definitionCache !== null) {
            $this->definitionCache->set($cacheKey, $resolvedLazyEntries);
        }

        return $resolvedLazyEntries;
    }

    private function cacheStorage(): ?CompiledCacheStorage
    {
        if ($this->compilationDirectory === null) {
            return null;
        }

        return new CompiledCacheStorage($this->compilationDirectory);
    }

    /**
     * @return class-string<Container>
     */
    private function resolveContainerClass(string $fingerprint): string
    {
        if ($this->compilationDirectory === null) {
            return Container::class;
        }

        $className = 'CompiledContainer_' . preg_replace(
            '/[^a-zA-Z0-9_]/',
            '',
            $fingerprint . '_' . sha1($this->compilationDirectory),
        );
        $namespace = 'PhpSoftBox\\Container\\Compilation\\Generated';
        $fqcn      = $namespace . '\\' . $className;
        $file      = $this->compilationDirectory . '/' . $className . '.php';

        if (!class_exists($fqcn, false)) {
            if (!file_exists($file)) {
                $code = $this->renderCompiledContainerClass($namespace, $className);
                if (file_put_contents($file, $code, LOCK_EX) === false) {
                    throw new RuntimeException('Failed to write compiled container class file: ' . $file);
                }
            }

            require_once $file;
        }

        if (!class_exists($fqcn)) {
            throw new RuntimeException('Compiled container class not found after generation: ' . $fqcn);
        }

        return $fqcn;
    }

    private function renderCompiledContainerClass(string $namespace, string $className): string
    {
        return new AotContainerClassGenerator(
            definitions: $this->definitions,
            decorators: $this->decorators,
            lazyEntries: $this->lazyEntries,
            autowiring: $this->autowiring,
            attributes: $this->attributes,
        )->render($namespace, $className);
    }
}
