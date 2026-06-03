<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Tests;

use PhpSoftBox\Container\Compilation\DefinitionCacheInterface;
use PhpSoftBox\Container\Container;
use PhpSoftBox\Container\ContainerBuilder;
use PhpSoftBox\Container\Definition\AddDefinition;
use PhpSoftBox\Container\Exception\ContainerException;
use PhpSoftBox\Container\Factory\RequestedEntry;
use PhpSoftBox\Container\Tests\Fixture\AttributeConstructorConsumer;
use PhpSoftBox\Container\Tests\Fixture\AttributeDependency;
use PhpSoftBox\Container\Tests\Fixture\AttributePropertyConsumer;
use PhpSoftBox\Container\Tests\Fixture\CircularA;
use PhpSoftBox\Container\Tests\Fixture\CircularB;
use PhpSoftBox\Container\Tests\Fixture\LazyContract;
use PhpSoftBox\Container\Tests\Fixture\LazyContractImpl;
use PhpSoftBox\Container\Tests\Fixture\LazyTrackedService;
use PhpSoftBox\Container\Tests\Fixture\RequiredScalarTarget;
use PhpSoftBox\Container\Tests\Fixture\ServiceWithDependency;
use PhpSoftBox\Container\Tests\Fixture\ServiceWithLazyDependency;
use PhpSoftBox\Container\Tests\Fixture\SimpleDependency;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use RuntimeException;
use stdClass;

use function array_column;
use function array_key_exists;
use function bin2hex;
use function clearstatcache;
use function file_exists;
use function file_put_contents;
use function filemtime;
use function glob;
use function is_dir;
use function method_exists;
use function PhpSoftBox\Container\add;
use function PhpSoftBox\Container\autowire;
use function PhpSoftBox\Container\decorate;
use function PhpSoftBox\Container\factory;
use function PhpSoftBox\Container\lazy;
use function random_bytes;
use function rmdir;
use function sleep;
use function sys_get_temp_dir;
use function unlink;

final class ContainerBuilderTest extends TestCase
{
    public function testBuildsContainerFromArrayDefinitions(): void
    {
        $builder = new ContainerBuilder();

        $builder->useAutowiring(true);
        $builder->useAttributes(false);
        $builder->addDefinitions([
            'value'         => 10,
            stdClass::class => static fn (): stdClass => new stdClass(),
        ]);

        $container = $builder->build();

        $this->assertInstanceOf(Container::class, $container);
        $this->assertSame(10, $container->get('value'));
        $this->assertInstanceOf(stdClass::class, $container->get(stdClass::class));
    }

    public function testAddDefinitionsFromFile(): void
    {
        $file = sys_get_temp_dir() . '/psb_container_defs_' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($file, "<?php\nreturn ['foo' => 'bar'];\n");

        try {
            $builder = new ContainerBuilder();

            $builder->addDefinitions($file);
            $container = $builder->build();

            $this->assertSame('bar', $container->get('foo'));
        } finally {
            @unlink($file);
        }
    }

    public function testDecoratorsFromDefinitionsAreAppliedInRegistrationOrder(): void
    {
        $builder = new ContainerBuilder();

        $builder->addDefinitions([
                    'service' => factory(static fn (): string => 'base'),
                ]);
        $builder->addDefinitions([
            'service' => decorate(static fn (string $previous): string => $previous . '-one'),
        ]);
        $builder->addDefinitions([
            'service' => decorate(static fn (string $previous): string => $previous . '-two'),
        ]);

        $container = $builder->build();

        $this->assertSame('base-one-two', $container->get('service'));
    }

    public function testBuilderDecorateMethodRegistersDecorator(): void
    {
        $builder = new ContainerBuilder();

        $builder->addDefinitions([
                    'service' => factory(static fn (): string => 'base'),
                ]);
        $builder->decorate('service', static fn (string $previous): string => $previous . '-decorated');

        $container = $builder->build();

        $this->assertSame('base-decorated', $container->get('service'));
    }

    public function testBuilderLazyDelaysServiceInitializationWithoutApiBreak(): void
    {
        LazyTrackedService::$instances = 0;

        $builder = new ContainerBuilder();

        $builder->addDefinitions([
                    LazyTrackedService::class => autowire(LazyTrackedService::class),
                ]);
        $builder->lazy(LazyTrackedService::class);

        $container = $builder->build();
        $service   = $container->get(LazyTrackedService::class);

        $this->assertSame(0, LazyTrackedService::$instances);
        $this->assertInstanceOf(LazyTrackedService::class, $service);
        $this->assertTrue(new ReflectionClass(LazyTrackedService::class)->isUninitializedLazyObject($service));

        $this->assertSame('lazy-1', $service->marker());
        $this->assertSame(1, LazyTrackedService::$instances);
        $this->assertSame($service, $container->get(LazyTrackedService::class));
    }

    public function testLazyDependencyIsInjectedIntoTypedConstructorAsProxy(): void
    {
        LazyTrackedService::$instances = 0;

        $builder = new ContainerBuilder();

        $builder->useAutowiring(true);
        $builder->addDefinitions([
            ServiceWithLazyDependency::class => autowire(ServiceWithLazyDependency::class),
            LazyTrackedService::class        => autowire(LazyTrackedService::class),
        ]);
        $builder->lazy(LazyTrackedService::class);

        $container = $builder->build();
        $consumer  = $container->get(ServiceWithLazyDependency::class);

        $this->assertInstanceOf(ServiceWithLazyDependency::class, $consumer);
        $this->assertInstanceOf(LazyTrackedService::class, $consumer->service);
        $this->assertSame(0, LazyTrackedService::$instances);

        $this->assertSame('lazy-1', $consumer->service->marker());
        $this->assertSame(1, LazyTrackedService::$instances);
    }

    public function testLazyCanBeConfiguredWithHelperInDefinitions(): void
    {
        LazyContractImpl::$instances = 0;

        $builder = new ContainerBuilder();

        $builder->addDefinitions([
                    LazyContractImpl::class => autowire(LazyContractImpl::class),
                    LazyContract::class     => lazy(LazyContractImpl::class, LazyContractImpl::class),
                ]);

        $container = $builder->build();
        $contract  = $container->get(LazyContract::class);

        $this->assertInstanceOf(LazyContract::class, $contract);
        $this->assertSame(0, LazyContractImpl::$instances);

        $this->assertSame('contract-1', $contract->code());
        $this->assertSame(1, LazyContractImpl::$instances);
    }

    public function testLazyEntryWithoutConcreteClassThrowsReadableError(): void
    {
        $builder = new ContainerBuilder();

        $builder->addDefinitions([
                    LazyContract::class => factory(static fn () => new LazyContractImpl()),
                ]);
        $builder->lazy(LazyContract::class);

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('requires a concrete class');

        $builder->build();
    }

    public function testEnableCompilationCreatesDirectory(): void
    {
        $dir       = sys_get_temp_dir() . '/psb_container_cache_' . bin2hex(random_bytes(4));
        $cacheFile = $dir . '/container.compiled.php';

        try {
            $builder = new ContainerBuilder();

            $builder->enableCompilation($dir);
            $container = $builder->build();

            $this->assertTrue(is_dir($dir));
            $this->assertInstanceOf(Container::class, $container);
        } finally {
            foreach (glob($dir . '/CompiledContainer_*.php') ?: [] as $file) {
                @unlink($file);
            }
            @unlink($cacheFile);
            @rmdir($dir);
        }
    }

    public function testEnableCompilationWritesAndReusesCompiledCache(): void
    {
        $dir       = sys_get_temp_dir() . '/psb_container_cache_' . bin2hex(random_bytes(4));
        $cacheFile = $dir . '/container.compiled.php';

        try {
            $builder = new ContainerBuilder();

            $builder->enableCompilation($dir);
            $builder->addDefinitions([
                LazyTrackedService::class => autowire(LazyTrackedService::class),
            ]);
            $builder->lazy(LazyTrackedService::class);

            $builder->build();

            $this->assertFileExists($cacheFile);
            $compiled = require $cacheFile;
            $this->assertIsArray($compiled);
            $this->assertSame(
                LazyTrackedService::class,
                $compiled['lazyEntries'][LazyTrackedService::class] ?? null,
            );

            clearstatcache(true, $cacheFile);
            $firstMTime = filemtime($cacheFile);
            sleep(1);
            $builder->build();
            clearstatcache(true, $cacheFile);
            $secondMTime = filemtime($cacheFile);

            $this->assertSame($firstMTime, $secondMTime);
        } finally {
            foreach (glob($dir . '/CompiledContainer_*.php') ?: [] as $file) {
                @unlink($file);
            }
            @unlink($cacheFile);
            @rmdir($dir);
        }
    }

    public function testWarmupCanInitializeLazyEntries(): void
    {
        LazyTrackedService::$instances = 0;

        $builder = new ContainerBuilder();

        $builder->addDefinitions([
                    LazyTrackedService::class => autowire(LazyTrackedService::class),
                ]);
        $builder->lazy(LazyTrackedService::class);

        $container = $builder->warmup();
        $service   = $container->get(LazyTrackedService::class);

        $this->assertSame(1, LazyTrackedService::$instances);
        $this->assertFalse(new ReflectionClass(LazyTrackedService::class)->isUninitializedLazyObject($service));
    }

    public function testWarmupCanSkipLazyInitialization(): void
    {
        LazyTrackedService::$instances = 0;

        $builder = new ContainerBuilder();

        $builder->addDefinitions([
                    LazyTrackedService::class => autowire(LazyTrackedService::class),
                ]);
        $builder->lazy(LazyTrackedService::class);

        $container = $builder->warmup([], false);
        $service   = $container->get(LazyTrackedService::class);

        $this->assertSame(0, LazyTrackedService::$instances);
        $this->assertTrue(new ReflectionClass(LazyTrackedService::class)->isUninitializedLazyObject($service));
        $this->assertSame('lazy-1', $service->marker());
        $this->assertSame(1, LazyTrackedService::$instances);
    }

    public function testValidateReportsDependencyGraphIssuesWithoutWarmup(): void
    {
        $builder = new ContainerBuilder([
            RequiredScalarTarget::class => autowire(RequiredScalarTarget::class),
        ]);

        $report = $builder->validate();

        $this->assertFalse($report['valid']);
        $this->assertContains(RequiredScalarTarget::class, $report['checked']);
        $this->assertContains('required-parameter', array_column($report['issues'], 'reason'));
    }

    public function testBuilderSupportsAddHelperForArrayDefinitions(): void
    {
        $builder = new ContainerBuilder();

        $builder->addDefinitions([
            'config' => ['a' => 1],
        ]);
        $builder->addDefinitions([
            'config' => add(['b' => 2]),
        ]);

        $container = $builder->build();

        $this->assertSame(['a' => 1, 'b' => 2], $container->get('config'));
    }

    public function testBuilderSupportsDeepAddHelperForArrayDefinitions(): void
    {
        $builder = new ContainerBuilder();

        $builder->addDefinitions([
            'config' => ['db' => ['hosts' => ['primary'], 'options' => ['ssl' => false]]],
        ]);
        $builder->addDefinitions([
            'config' => add(
                ['db' => ['hosts' => ['replica'], 'options' => ['timeout' => 10]]],
                AddDefinition::MERGE_DEEP,
            ),
        ]);

        $container = $builder->build();

        $this->assertSame([
            'db' => [
                'hosts'   => ['primary', 'replica'],
                'options' => ['ssl' => false, 'timeout' => 10],
            ],
        ], $container->get('config'));
    }

    public function testBuilderCanWrapExternalContainer(): void
    {
        $wrapped = new class () implements ContainerInterface {
            public function get(string $id): mixed
            {
                return $id === 'ext' ? 'wrapped' : null;
            }

            public function has(string $id): bool
            {
                return $id === 'ext';
            }
        };

        $builder = new ContainerBuilder();

        $builder->wrapContainer($wrapped);

        $container = $builder->build();
        $this->assertSame('wrapped', $container->get('ext'));
    }

    public function testEnableCompilationGeneratesCompiledContainerClassFile(): void
    {
        $dir = sys_get_temp_dir() . '/psb_container_cache_' . bin2hex(random_bytes(4));

        try {
            $builder = new ContainerBuilder();

            $builder->enableCompilation($dir);
            $container = $builder->build();

            $this->assertInstanceOf(Container::class, $container);
            $this->assertNotSame(Container::class, $container::class);

            $compiledClassFileExists = false;
            foreach (glob($dir . '/CompiledContainer_*.php') ?: [] as $file) {
                if (file_exists($file)) {
                    $compiledClassFileExists = true;
                    @unlink($file);
                }
            }

            @unlink($dir . '/container.compiled.php');
            @rmdir($dir);

            $this->assertTrue($compiledClassFileExists);
        } finally {
            foreach (glob($dir . '/CompiledContainer_*.php') ?: [] as $file) {
                @unlink($file);
            }
            @unlink($dir . '/container.compiled.php');
            @rmdir($dir);
        }
    }

    public function testEnableCompilationGeneratesAotFastPathForSupportedDefinitions(): void
    {
        $dir = sys_get_temp_dir() . '/psb_container_cache_' . bin2hex(random_bytes(4));

        try {
            $builder = new ContainerBuilder();

            $builder->enableCompilation($dir);
            $builder->addDefinitions([
                'answer'                     => 42,
                SimpleDependency::class      => autowire(SimpleDependency::class),
                ServiceWithDependency::class => autowire(ServiceWithDependency::class),
            ]);

            $container = $builder->build();

            $this->assertTrue(method_exists($container, '__compiledEntries'));

            $compiledEntries = $container->__compiledEntries();
            $this->assertSame('value', $compiledEntries['answer'] ?? null);
            $this->assertSame('object', $compiledEntries[SimpleDependency::class] ?? null);
            $this->assertSame('object', $compiledEntries[ServiceWithDependency::class] ?? null);

            $service = $container->get(ServiceWithDependency::class);
            $this->assertInstanceOf(ServiceWithDependency::class, $service);
            $this->assertSame('dep', $service->dependency->name);

            $this->assertSame(42, $container->get('answer'));
            $container->set('answer', 7);
            $this->assertSame(7, $container->get('answer'));
        } finally {
            foreach (glob($dir . '/CompiledContainer_*.php') ?: [] as $file) {
                @unlink($file);
            }
            @unlink($dir . '/container.compiled.php');
            @rmdir($dir);
        }
    }

    public function testEnableCompilationGeneratesAotFastPathForDecoratedDefinitions(): void
    {
        $dir         = sys_get_temp_dir() . '/psb_container_cache_' . bin2hex(random_bytes(4));
        $decorations = 0;

        try {
            $builder = new ContainerBuilder();

            $builder->enableCompilation($dir);
            $builder->addDefinitions([
                'answer' => 41,
            ]);
            $builder->decorate('answer', static function (int $previous) use (&$decorations): int {
                $decorations++;

                return $previous + $decorations;
            });

            $container = $builder->build();

            $this->assertTrue(method_exists($container, '__compiledEntries'));

            $compiledEntries = $container->__compiledEntries();
            $this->assertSame('decorated-value', $compiledEntries['answer'] ?? null);

            $this->assertSame(42, $container->get('answer'));
            $this->assertSame(42, $container->get('answer'));
            $this->assertSame(1, $decorations);

            $this->assertSame(43, $container->make('answer'));
            $this->assertSame(2, $decorations);
        } finally {
            foreach (glob($dir . '/CompiledContainer_*.php') ?: [] as $file) {
                @unlink($file);
            }
            @unlink($dir . '/container.compiled.php');
            @rmdir($dir);
        }
    }

    public function testEnableCompilationGeneratesAotFastPathForFactoryDefinitions(): void
    {
        $dir     = sys_get_temp_dir() . '/psb_container_cache_' . bin2hex(random_bytes(4));
        $created = 0;

        try {
            $builder = new ContainerBuilder();

            $builder->enableCompilation($dir);
            $builder->addDefinitions([
                'service' => factory(static function (RequestedEntry $entry) use (&$created): string {
                    $created++;

                    return $entry->getName() . '-' . $created;
                }),
            ]);

            $container = $builder->build();

            $this->assertTrue(method_exists($container, '__compiledEntries'));

            $compiledEntries = $container->__compiledEntries();
            $this->assertSame('factory', $compiledEntries['service'] ?? null);

            $this->assertSame('service-1', $container->get('service'));
            $this->assertSame('service-1', $container->get('service'));
            $this->assertSame(1, $created);

            $this->assertSame('service-2', $container->make('service'));
            $this->assertSame(2, $created);
        } finally {
            foreach (glob($dir . '/CompiledContainer_*.php') ?: [] as $file) {
                @unlink($file);
            }
            @unlink($dir . '/container.compiled.php');
            @rmdir($dir);
        }
    }

    public function testEnableCompilationGeneratesAotFastPathForAttributeInjection(): void
    {
        $dir = sys_get_temp_dir() . '/psb_container_cache_' . bin2hex(random_bytes(4));

        try {
            $builder = new ContainerBuilder();

            $builder->enableCompilation($dir);
            $builder->useAttributes(true);
            $builder->addDefinitions([
                'custom.attribute.dep'              => new AttributeDependency('named'),
                AttributeDependency::class          => new AttributeDependency('typed'),
                AttributeConstructorConsumer::class => autowire(AttributeConstructorConsumer::class),
                AttributePropertyConsumer::class    => autowire(AttributePropertyConsumer::class),
            ]);

            $container = $builder->build();

            $this->assertTrue(method_exists($container, '__compiledEntries'));

            $compiledEntries = $container->__compiledEntries();
            $this->assertSame('object', $compiledEntries[AttributeConstructorConsumer::class] ?? null);
            $this->assertSame('object', $compiledEntries[AttributePropertyConsumer::class] ?? null);

            $constructorConsumer = $container->get(AttributeConstructorConsumer::class);
            $propertyConsumer    = $container->get(AttributePropertyConsumer::class);

            $this->assertSame('named', $constructorConsumer->dependency->value);
            $this->assertSame('typed', $propertyConsumer->dependency->value);
        } finally {
            foreach (glob($dir . '/CompiledContainer_*.php') ?: [] as $file) {
                @unlink($file);
            }
            @unlink($dir . '/container.compiled.php');
            @rmdir($dir);
        }
    }

    public function testCompiledFastPathDetectsCircularDependencies(): void
    {
        $dir = sys_get_temp_dir() . '/psb_container_cache_' . bin2hex(random_bytes(4));

        try {
            $builder = new ContainerBuilder();

            $builder->enableCompilation($dir);
            $builder->addDefinitions([
                CircularA::class => autowire(CircularA::class),
                CircularB::class => autowire(CircularB::class),
            ]);

            $container = $builder->build();

            $this->assertTrue(method_exists($container, '__compiledEntries'));
            $this->assertSame('object', $container->__compiledEntries()[CircularA::class] ?? null);

            $this->expectException(ContainerException::class);
            $this->expectExceptionMessage('Circular dependency detected');

            $container->get(CircularA::class);
        } finally {
            foreach (glob($dir . '/CompiledContainer_*.php') ?: [] as $file) {
                @unlink($file);
            }
            @unlink($dir . '/container.compiled.php');
            @rmdir($dir);
        }
    }

    public function testBuilderCanUseDefinitionCache(): void
    {
        $cache = new class () implements DefinitionCacheInterface {
            /** @var array<string, mixed> */
            public array $storage = [];

            public function has(string $key): bool
            {
                return array_key_exists($key, $this->storage);
            }

            public function get(string $key): mixed
            {
                return $this->storage[$key] ?? null;
            }

            public function set(string $key, mixed $value, int $ttl = 0): void
            {
                $this->storage[$key] = $value;
            }

            public function delete(string $key): void
            {
                unset($this->storage[$key]);
            }
        };

        $builder = new ContainerBuilder();

        $builder->setDefinitionCache($cache);
        $builder->addDefinitions([
            LazyTrackedService::class => autowire(LazyTrackedService::class),
        ]);
        $builder->lazy(LazyTrackedService::class);

        $builder->build();
        $this->assertNotSame([], $cache->storage);
    }

    public function testBuilderCanInvalidateCompilationCache(): void
    {
        $dir       = sys_get_temp_dir() . '/psb_container_cache_' . bin2hex(random_bytes(4));
        $cacheFile = $dir . '/container.compiled.php';
        $cache     = new class () implements DefinitionCacheInterface {
            /** @var array<string, mixed> */
            public array $storage = [];

            public function has(string $key): bool
            {
                return array_key_exists($key, $this->storage);
            }

            public function get(string $key): mixed
            {
                return $this->storage[$key] ?? null;
            }

            public function set(string $key, mixed $value, int $ttl = 0): void
            {
                $this->storage[$key] = $value;
            }

            public function delete(string $key): void
            {
                unset($this->storage[$key]);
            }
        };

        try {
            $builder = new ContainerBuilder();

            $builder->enableCompilation($dir);
            $builder->setDefinitionCache($cache);
            $builder->addDefinitions([
                LazyTrackedService::class => autowire(LazyTrackedService::class),
            ]);
            $builder->lazy(LazyTrackedService::class);

            $builder->build();
            $this->assertFileExists($cacheFile);
            $this->assertNotSame([], $cache->storage);

            $builder->invalidateCompilationCache();

            $this->assertFileDoesNotExist($cacheFile);
            $this->assertSame([], glob($dir . '/CompiledContainer_*.php') ?: []);
            $this->assertSame([], $cache->storage);
        } finally {
            foreach (glob($dir . '/CompiledContainer_*.php') ?: [] as $file) {
                @unlink($file);
            }
            @unlink($cacheFile);
            @rmdir($dir);
        }
    }

    public function testAddDefinitionsFileMustReturnArray(): void
    {
        $file = sys_get_temp_dir() . '/psb_container_defs_' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($file, "<?php\nreturn new stdClass();\n");

        try {
            $builder = new ContainerBuilder();

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Definitions file must return array');
            $builder->addDefinitions($file);
        } finally {
            @unlink($file);
        }
    }
}
