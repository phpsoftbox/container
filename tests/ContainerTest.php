<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Tests;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use PhpSoftBox\Container\Container;
use PhpSoftBox\Container\Definition\AddDefinition;
use PhpSoftBox\Container\Definition\FactoryDefinition;
use PhpSoftBox\Container\Diagnostics\ContainerDiagnostics;
use PhpSoftBox\Container\Exception\ContainerException;
use PhpSoftBox\Container\Exception\NotFoundException;
use PhpSoftBox\Container\Factory\RequestedEntry;
use PhpSoftBox\Container\FactoryInterface;
use PhpSoftBox\Container\InvokerInterface;
use PhpSoftBox\Container\Tests\Fixture\AbstractDependency;
use PhpSoftBox\Container\Tests\Fixture\AttributeConstructorConsumer;
use PhpSoftBox\Container\Tests\Fixture\AttributeDependency;
use PhpSoftBox\Container\Tests\Fixture\AttributeMethodConsumer;
use PhpSoftBox\Container\Tests\Fixture\AttributeNamedPropertyConsumer;
use PhpSoftBox\Container\Tests\Fixture\AttributePropertyConsumer;
use PhpSoftBox\Container\Tests\Fixture\CallableTarget;
use PhpSoftBox\Container\Tests\Fixture\ConfiguredInjectionTarget;
use PhpSoftBox\Container\Tests\Fixture\InjectableLazyService;
use PhpSoftBox\Container\Tests\Fixture\LazyTrackedService;
use PhpSoftBox\Container\Tests\Fixture\RequiredScalarTarget;
use PhpSoftBox\Container\Tests\Fixture\ServiceWithDependency;
use PhpSoftBox\Container\Tests\Fixture\ServiceWithLazyDependency;
use PhpSoftBox\Container\Tests\Fixture\SimpleDependency;
use PhpSoftBox\Container\Tests\Fixture\UserDoctrineRepository;
use PhpSoftBox\Container\Tests\Fixture\UserRepositoryInterface;
use PhpSoftBox\Container\Tests\Fixture\VariadicConstructorTarget;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;
use stdClass;
use Traversable;

use function array_column;
use function implode;
use function PhpSoftBox\Container\add;
use function PhpSoftBox\Container\autowire;
use function PhpSoftBox\Container\create;
use function PhpSoftBox\Container\decorate;
use function PhpSoftBox\Container\env;
use function PhpSoftBox\Container\factory;
use function PhpSoftBox\Container\get;
use function PhpSoftBox\Container\lazy;
use function PhpSoftBox\Container\string as diString;
use function PhpSoftBox\Container\value;

final class ContainerTest extends TestCase
{
    public function testCanStoreAndResolveScalarValue(): void
    {
        $container = new Container();

        $container->set('app.name', 'phpsoftbox');

        $this->assertTrue($container->has('app.name'));
        $this->assertSame('phpsoftbox', $container->get('app.name'));
    }

    public function testExposesAutowiringAndAttributesFlags(): void
    {
        $defaultContainer = new Container();
        $customContainer  = new Container([], false, [], [], true);

        $this->assertTrue($defaultContainer->autowiringEnabled());
        $this->assertFalse($defaultContainer->attributesEnabled());

        $this->assertFalse($customContainer->autowiringEnabled());
        $this->assertTrue($customContainer->attributesEnabled());
    }

    public function testFactoryIsSharedForGetButNotForMake(): void
    {
        $container = new Container([
            'obj' => factory(static fn (): stdClass => new stdClass()),
        ]);

        $firstGet   = $container->get('obj');
        $secondGet  = $container->get('obj');
        $firstMake  = $container->make('obj');
        $secondMake = $container->make('obj');

        $this->assertSame($firstGet, $secondGet);
        $this->assertNotSame($firstMake, $secondMake);
        $this->assertNotSame($firstGet, $firstMake);
    }

    public function testAliasResolvesTargetEntry(): void
    {
        $container = new Container([
            'primary' => factory(static fn (): stdClass => new stdClass()),
            'alias'   => get('primary'),
        ]);

        $this->assertSame($container->get('primary'), $container->get('alias'));
        $this->assertNotSame($container->get('primary'), $container->make('alias'));
    }

    public function testAutowiresUndefinedClass(): void
    {
        $container = new Container([
            SimpleDependency::class => value(new SimpleDependency('autowired')),
        ]);

        $service = $container->get(ServiceWithDependency::class);

        $this->assertInstanceOf(ServiceWithDependency::class, $service);
        $this->assertSame('autowired', $service->dependency->name);
    }

    public function testMakeOverridesConstructorParameters(): void
    {
        $container = new Container([
            ServiceWithDependency::class => autowire(ServiceWithDependency::class)
                ->constructor(get(SimpleDependency::class), 'default-label'),
            SimpleDependency::class => value(new SimpleDependency('default-dep')),
        ]);

        $service = $container->make(ServiceWithDependency::class, ['label' => 'custom-label']);

        $this->assertSame('default-dep', $service->dependency->name);
        $this->assertSame('custom-label', $service->label);
    }

    public function testCallInjectsNamedAndTypedArguments(): void
    {
        $container = new Container([
            SimpleDependency::class => value(new SimpleDependency('typed')),
            CallableTarget::class   => autowire(CallableTarget::class),
        ]);

        $result = $container->call([CallableTarget::class, 'handle'], ['id' => '42']);

        $this->assertSame('42:typed', $result);
    }

    public function testCallCoercesExplicitScalarArgumentsLikeReflectionInvoker(): void
    {
        $container = new Container();
        $target    = new class () {
            public function __invoke(int $notificationId): int
            {
                return $notificationId;
            }
        };

        $this->assertSame(23, $container->call($target, ['notificationId' => '23']));
    }

    public function testCallSupportsVariadicPositionalArguments(): void
    {
        $container = new Container();

        $result = $container->call(
            static fn (string $prefix, string ...$items): string => $prefix . ':' . implode(',', $items),
            ['head', 'one', 'two'],
        );

        $this->assertSame('head:one,two', $result);
    }

    public function testMakeSupportsVariadicConstructorArguments(): void
    {
        $container = new Container([
            VariadicConstructorTarget::class => autowire(VariadicConstructorTarget::class),
        ]);

        $target = $container->make(VariadicConstructorTarget::class, ['base', 'x', 'y']);

        $this->assertSame('base', $target->prefix);
        $this->assertSame(['x', 'y'], $target->items);
    }

    public function testCallResolvesUnionTypeWithSingleCandidate(): void
    {
        $container = new Container([
            SimpleDependency::class => value(new SimpleDependency('union')),
        ], false);

        $result = $container->call(
            static fn (SimpleDependency|stdClass $dependency): string => $dependency instanceof SimpleDependency
                ? $dependency->name
                : 'std',
        );

        $this->assertSame('union', $result);
    }

    public function testCallThrowsOnAmbiguousUnionTypeResolution(): void
    {
        $container = new Container([
            SimpleDependency::class => value(new SimpleDependency('first')),
            stdClass::class         => value(new stdClass()),
        ], false);

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Ambiguous union type resolution');

        $container->call(static fn (SimpleDependency|stdClass $dependency): mixed => $dependency);
    }

    public function testCallResolvesIntersectionType(): void
    {
        $composite = new class () implements Countable, IteratorAggregate {
            public function count(): int
            {
                return 1;
            }

            public function getIterator(): Traversable
            {
                return new ArrayIterator(['item']);
            }
        };

        $container = new Container([
            Countable::class => value($composite),
        ], false);

        $result = $container->call(
            static fn (Countable&IteratorAggregate $dependency): int => $dependency->count(),
        );

        $this->assertSame(1, $result);
    }

    public function testCallThrowsWhenScalarParameterCannotBeResolved(): void
    {
        $container = new Container();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Unable to resolve callable parameter');

        $container->call(static fn (string $required): string => $required);
    }

    public function testInlineDefinitionHelperInMakeParametersIsResolved(): void
    {
        $container = new Container([
            SimpleDependency::class      => value(new SimpleDependency('default')),
            ServiceWithDependency::class => autowire(ServiceWithDependency::class),
        ]);

        $service = $container->make(ServiceWithDependency::class, [
            'dependency' => factory(static fn (): SimpleDependency => new SimpleDependency('inline')),
        ]);

        $this->assertSame('inline', $service->dependency->name);
    }

    public function testCircularAliasesThrowReadableError(): void
    {
        $container = new Container([
            'a' => get('b'),
            'b' => get('a'),
        ]);

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Circular dependency detected: a -> b -> a');

        $container->get('a');
    }

    public function testHasReturnsFalseForNonInstantiableAutowireClass(): void
    {
        $container = new Container();

        $this->assertFalse($container->has(AbstractDependency::class));
    }

    public function testUnknownEntryThrowsNotFound(): void
    {
        $container = new Container();

        $this->expectException(NotFoundException::class);
        $container->get('unknown.entry');
    }

    public function testRawClosureSetActsAsFactory(): void
    {
        $container = new Container();

        $container->set('clock', static fn (): stdClass => new stdClass());

        $this->assertSame($container->get('clock'), $container->get('clock'));
        $this->assertNotSame($container->make('clock'), $container->make('clock'));
    }

    public function testObjectDefinitionCanBeRegisteredDirectly(): void
    {
        $container = new Container([
            ServiceWithDependency::class => autowire(ServiceWithDependency::class)
                ->constructor(get(SimpleDependency::class), 'x')
                ->toDefinition(ServiceWithDependency::class),
            SimpleDependency::class => value(new SimpleDependency('direct')),
        ]);

        $service = $container->get(ServiceWithDependency::class);
        $this->assertSame('direct', $service->dependency->name);
        $this->assertSame('x', $service->label);
    }

    public function testFactoryDefinitionCanUseStringFactoryClass(): void
    {
        $factory = new class () {
            public function __invoke(): stdClass
            {
                return new stdClass();
            }
        };

        $container = new Container([
            'factoryService' => value($factory),
            'service'        => new FactoryDefinition('factoryService'),
        ]);

        $this->assertInstanceOf(stdClass::class, $container->get('service'));
    }

    public function testDecoratorCanBeRegisteredViaSetAndAppliesToGetAndMake(): void
    {
        $counter   = 0;
        $container = new Container();

        $container->set('service', factory(static function () use (&$counter): string {
            $counter++;

            return 'base-' . $counter;
        }));
        $container->set('service', decorate(static fn (string $previous): string => $previous . '-decorated'));

        $this->assertSame('base-1-decorated', $container->get('service'));
        $this->assertSame('base-1-decorated', $container->get('service'));
        $this->assertSame('base-2-decorated', $container->make('service'));
    }

    public function testLazyCanBeRegisteredAtRuntimeViaSet(): void
    {
        LazyTrackedService::$instances = 0;

        $container = new Container([
            LazyTrackedService::class        => autowire(LazyTrackedService::class),
            ServiceWithLazyDependency::class => autowire(ServiceWithLazyDependency::class),
        ]);

        $container->set(LazyTrackedService::class, lazy());

        $consumer = $container->get(ServiceWithLazyDependency::class);

        $this->assertInstanceOf(ServiceWithLazyDependency::class, $consumer);
        $this->assertSame(0, LazyTrackedService::$instances);
        $this->assertSame('lazy-1', $consumer->service->marker());
        $this->assertSame(1, LazyTrackedService::$instances);
    }

    public function testCreateSupportsMethodAndPropertyInjectionConfiguration(): void
    {
        $container = new Container([
            ConfiguredInjectionTarget::class => create(ConfiguredInjectionTarget::class)
                ->property('propertyDependency', get(SimpleDependency::class))
                ->method('configure', get(SimpleDependency::class), 'configured'),
            SimpleDependency::class => value(new SimpleDependency('from-definition')),
        ]);

        $target = $container->get(ConfiguredInjectionTarget::class);

        $this->assertInstanceOf(ConfiguredInjectionTarget::class, $target);
        $this->assertSame('from-definition', $target->propertyDependency?->name);
        $this->assertSame('from-definition', $target->methodDependency?->name);
        $this->assertSame('configured', $target->label);
    }

    public function testAddHelperExtendsArrayDefinitions(): void
    {
        $container = new Container([
            'config' => ['first' => 1],
        ]);

        $container->set('config', add(['second' => 2]));

        $this->assertSame(['first' => 1, 'second' => 2], $container->get('config'));
    }

    public function testAddHelperCanDeepMergeNestedArrayDefinitions(): void
    {
        $container = new Container([
            'config' => [
                'db' => [
                    'hosts'   => ['primary'],
                    'options' => ['ssl' => false],
                    'port'    => 3306,
                ],
                'debug' => false,
            ],
        ]);

        $container->set('config', add([
            'db' => [
                'hosts'   => ['replica'],
                'options' => ['timeout' => 10],
            ],
            'debug' => true,
        ], AddDefinition::MERGE_DEEP));

        $this->assertSame([
            'db' => [
                'hosts'   => ['primary', 'replica'],
                'options' => ['ssl' => false, 'timeout' => 10],
                'port'    => 3306,
            ],
            'debug' => true,
        ], $container->get('config'));
    }

    public function testStringDefinitionInterpolatesContainerEntries(): void
    {
        $container = new Container([
            'base.path' => '/srv/app',
            'log.path'  => diString('{base.path}/var/log/app.log'),
        ]);

        $this->assertSame('/srv/app/var/log/app.log', $container->get('log.path'));
    }

    public function testStringInterpolationSupportsEscapingAndTransforms(): void
    {
        $container = new Container([
            'name'       => 'PhpSoftBox',
            'path'       => '/srv/app log',
            'json.value' => ['enabled' => true],
            'line'       => diString('{{name}} {name|lower} {name|upper} {path|urlencode} {json.value|json}'),
        ]);

        $this->assertSame(
            '{name} phpsoftbox PHPSOFTBOX %2Fsrv%2Fapp+log {"enabled":true}',
            $container->get('line'),
        );
    }

    public function testEnvDefinitionUsesEnvironmentAndDefault(): void
    {
        $container = new Container([
            'env.value'   => env('PSB_TEST_ENV', 'fallback'),
            'env.missing' => env('PSB_TEST_MISSING', 'default-value'),
        ]);

        $_ENV['PSB_TEST_ENV'] = 'from-env';
        try {
            $this->assertSame('from-env', $container->get('env.value'));
            $this->assertSame('default-value', $container->get('env.missing'));
        } finally {
            unset($_ENV['PSB_TEST_ENV']);
        }
    }

    public function testWildcardDefinitionsMapInterfacesToImplementations(): void
    {
        $container = new Container([
            'PhpSoftBox\\Container\\Tests\\Fixture\\*RepositoryInterface'
                => create('PhpSoftBox\\Container\\Tests\\Fixture\\*DoctrineRepository'),
        ]);

        $repository = $container->get(UserRepositoryInterface::class);

        $this->assertInstanceOf(UserDoctrineRepository::class, $repository);
        $this->assertSame('doctrine', $repository->source());
    }

    public function testWildcardDefinitionsPreferMostSpecificPattern(): void
    {
        $container = new Container([
            'PhpSoftBox\\Container\\Tests\\Fixture\\*Interface'           => value('broad'),
            'PhpSoftBox\\Container\\Tests\\Fixture\\*RepositoryInterface' => create(
                'PhpSoftBox\\Container\\Tests\\Fixture\\*DoctrineRepository',
            ),
        ]);

        $repository = $container->get(UserRepositoryInterface::class);

        $this->assertInstanceOf(UserDoctrineRepository::class, $repository);
    }

    public function testDiagnosticsExposeResolutionReasonAndAliasTrace(): void
    {
        $container = new Container([
            'target' => 'value',
            'alias'  => get('target'),
        ], false);

        $this->assertInstanceOf(ContainerDiagnostics::class, $container->diagnostics());
        $this->assertTrue($container->canResolve('alias'));
        $this->assertFalse($container->canResolve('missing'));

        $why = $container->why('alias');
        $this->assertSame('definition', $why['source']);
        $this->assertSame('target', $why['target']);
        $this->assertFalse($why['lazy']);
        $this->assertSame(0, $why['decorators']);

        $trace = $container->trace('alias');
        $this->assertSame('alias', $trace[0]['id']);
        $this->assertSame('target', $trace[1]['id']);
        $this->assertSame('not-found', $container->why('missing')['source']);
    }

    public function testDiagnosticsGraphDescribesObjectDependencies(): void
    {
        $container = new Container([
            ConfiguredInjectionTarget::class => create(ConfiguredInjectionTarget::class)
                ->property('propertyDependency', get(SimpleDependency::class))
                ->method('configure', get(SimpleDependency::class), 'configured'),
            ServiceWithDependency::class => autowire(ServiceWithDependency::class),
            SimpleDependency::class      => autowire(SimpleDependency::class),
        ]);

        $constructorGraph = $container->graph(ServiceWithDependency::class);
        $this->assertSame(ServiceWithDependency::class, $constructorGraph['id']);
        $this->assertSame('definition', $constructorGraph['source']);
        $this->assertSame(SimpleDependency::class, $constructorGraph['dependencies'][0]['id']);
        $this->assertSame('constructor', $constructorGraph['dependencies'][0]['relation']);
        $this->assertSame('dependency', $constructorGraph['dependencies'][0]['parameter']);
        $this->assertSame('type-hint', $constructorGraph['dependencies'][0]['source']);
        $this->assertSame('definition', $constructorGraph['dependencies'][0]['node']['source']);

        $injectionGraph = $container->diagnostics()->graph(ConfiguredInjectionTarget::class);
        $this->assertSame(SimpleDependency::class, $injectionGraph['dependencies'][0]['id']);
        $this->assertSame('method', $injectionGraph['dependencies'][0]['relation']);
        $this->assertSame('configure', $injectionGraph['dependencies'][0]['method']);
        $this->assertSame(SimpleDependency::class, $injectionGraph['dependencies'][1]['id']);
        $this->assertSame('property', $injectionGraph['dependencies'][1]['relation']);
        $this->assertSame('propertyDependency', $injectionGraph['dependencies'][1]['property']);
    }

    public function testDiagnosticsAotExplainsEligibility(): void
    {
        $container = new Container([
            'answer'                     => 42,
            'factory'                    => factory(static fn (): stdClass => new stdClass()),
            'object.value'               => value(new stdClass()),
            'decorated'                  => 1,
            SimpleDependency::class      => autowire(SimpleDependency::class),
            ServiceWithDependency::class => autowire(ServiceWithDependency::class),
        ]);

        $container->set('decorated', decorate(static fn (int $previous): int => $previous + 1));

        $valueReport = $container->aot('answer');
        $this->assertTrue($valueReport['eligible']);
        $this->assertSame('value', $valueReport['kind']);
        $this->assertSame([], $valueReport['reasons']);

        $objectReport = $container->diagnostics()->aot(ServiceWithDependency::class);
        $this->assertTrue($objectReport['eligible']);
        $this->assertSame('object', $objectReport['kind']);

        $factoryReport = $container->aot('factory');
        $this->assertTrue($factoryReport['eligible']);
        $this->assertSame('factory', $factoryReport['kind']);

        $objectValueReport = $container->aot('object.value');
        $this->assertFalse($objectValueReport['eligible']);
        $this->assertContains('value-not-exportable', $objectValueReport['reasons']);

        $decoratedReport = $container->aot('decorated');
        $this->assertTrue($decoratedReport['eligible']);
        $this->assertSame('decorated-value', $decoratedReport['kind']);

        $plan = $container->aotPlan();
        $this->assertSame('value', $plan['answer']['kind'] ?? null);
        $this->assertSame('factory', $plan['factory']['kind'] ?? null);
        $this->assertSame('decorated-value', $plan['decorated']['kind'] ?? null);
        $this->assertFalse($plan['object.value']['eligible'] ?? true);
    }

    public function testDiagnosticsValidateReportsGraphIssues(): void
    {
        $container = new Container([
            'missing.alias'                  => get('missing.entry'),
            'missing.string'                 => diString('{missing.entry}'),
            'missing.method'                 => create(ConfiguredInjectionTarget::class)->method('missingMethod'),
            RequiredScalarTarget::class      => autowire(RequiredScalarTarget::class),
            VariadicConstructorTarget::class => autowire(VariadicConstructorTarget::class),
        ], false);

        $report  = $container->validate();
        $reasons = array_column($report['issues'], 'reason');

        $this->assertFalse($report['valid']);
        $this->assertContains('not-found', $reasons);
        $this->assertContains('required-parameter', $reasons);
        $this->assertContains('method-not-found', $reasons);
        $this->assertContains('missing.alias', $report['checked']);
        $this->assertNotContains('cycle', $reasons);
    }

    public function testDiagnosticsValidateReportsCycles(): void
    {
        $container = new Container([
            'a' => get('b'),
            'b' => get('a'),
        ], false);

        $report = $container->diagnostics()->validate(['a']);

        $this->assertFalse($report['valid']);
        $this->assertSame(['a'], $report['checked']);
        $this->assertContains('cycle', array_column($report['issues'], 'reason'));
        $this->assertSame(['a', 'b', 'a'], $report['issues'][0]['path']);
    }

    public function testDiagnosticsCanRenderTextReports(): void
    {
        $container = new Container([
            'answer'                     => 42,
            'missing.alias'              => get('missing.entry'),
            ServiceWithDependency::class => autowire(ServiceWithDependency::class),
            SimpleDependency::class      => autowire(SimpleDependency::class),
        ], false);

        $diagnostics = $container->diagnostics();

        $graph = $diagnostics->renderGraph(ServiceWithDependency::class);
        $this->assertStringContainsString('Dependency graph: ' . ServiceWithDependency::class, $graph);
        $this->assertStringContainsString('constructor ' . SimpleDependency::class, $graph);

        $validation = $diagnostics->renderValidation(['missing.alias']);
        $this->assertStringContainsString('Validation: failed', $validation);
        $this->assertStringContainsString('[not-found] missing.entry', $validation);

        $aotPlan = $diagnostics->renderAotPlan();
        $this->assertStringContainsString('AOT plan:', $aotPlan);
        $this->assertStringContainsString('- answer: eligible (value)', $aotPlan);
    }

    public function testInjectAttributesForAutowireObjectWhenEnabled(): void
    {
        $container = new Container([
            AttributeDependency::class => value(new AttributeDependency('attr-enabled')),
        ], true, [], [], true);

        $consumer = $container->get(AttributePropertyConsumer::class);

        $this->assertSame('attr-enabled', $consumer->dependency->value);
    }

    public function testInjectAttributesSupportNamedEntriesAndSetterInjection(): void
    {
        $container = new Container([
            AttributeDependency::class => value(new AttributeDependency('typed')),
            'custom.attribute.dep'     => value(new AttributeDependency('named')),
        ], true, [], [], true);

        $propertyConsumer    = $container->get(AttributeNamedPropertyConsumer::class);
        $methodConsumer      = $container->get(AttributeMethodConsumer::class);
        $constructorConsumer = $container->get(AttributeConstructorConsumer::class);

        $this->assertSame('named', $propertyConsumer->dependency->value);
        $this->assertSame('typed', $methodConsumer->dependency?->value);
        $this->assertSame('named', $constructorConsumer->dependency->value);
    }

    public function testInjectOnAppliesAttributeInjectionToExistingInstance(): void
    {
        $container = new Container([
            AttributeDependency::class => value(new AttributeDependency('inject-on')),
        ], true, [], [], true);

        $instance = new AttributePropertyConsumer();

        $container->injectOn($instance);

        $this->assertSame('inject-on', $instance->dependency->value);
    }

    public function testInjectableAttributeCanEnableImplicitLazyProxy(): void
    {
        InjectableLazyService::$instances = 0;

        $container = new Container([], true, [], [], true);

        $service = $container->get(InjectableLazyService::class);

        $this->assertInstanceOf(InjectableLazyService::class, $service);
        $this->assertSame(0, InjectableLazyService::$instances);
        $this->assertSame('injectable-lazy-1', $service->marker());
        $this->assertSame(1, InjectableLazyService::$instances);
    }

    public function testWrapContainerAllowsFallbackResolution(): void
    {
        $wrapped = new class () implements ContainerInterface {
            public function get(string $id): string
            {
                if ($id === 'external.value') {
                    return 'wrapped';
                }

                throw new class ('not found') extends RuntimeException implements NotFoundExceptionInterface {
                };
            }

            public function has(string $id): bool
            {
                return $id === 'external.value';
            }
        };

        $container = new Container([], true, [], [], false, $wrapped);

        $this->assertTrue($container->has('external.value'));
        $this->assertSame('wrapped', $container->get('external.value'));
    }

    public function testFactoryAndInvokerInterfacesResolveToContainer(): void
    {
        $container = new Container();

        $this->assertSame($container, $container->get(FactoryInterface::class));
        $this->assertSame($container, $container->get(InvokerInterface::class));
    }

    public function testFactoryCanReceiveRequestedEntryObject(): void
    {
        $container = new Container([
            'service.id' => factory(static fn (RequestedEntry $entry): string => $entry->getName()),
        ]);

        $this->assertSame('service.id', $container->get('service.id'));
    }
}
