<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Tests;

use PhpSoftBox\Container\Container;
use PhpSoftBox\Container\Tests\Fixture\RequiredScalarTarget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function PhpSoftBox\Container\string as diString;

#[CoversClass(Container::class)]
#[CoversMethod(Container::class, 'call')]
#[CoversMethod(Container::class, 'make')]
#[CoversMethod(Container::class, 'set')]
final class RuntimeParameterInterpolationTest extends TestCase
{
    /**
     * Проверим, что параметр маршрута с {entry.id} передаётся в обработчик как есть
     * и не раскрывает значение записи контейнера.
     *
     * @see Container::call()
     */
    #[Test]
    public function callPassesRouteParameterWithBracesAsIs(): void
    {
        $container = new Container(['db.password' => 'secret']);

        // Так Router передаёт параметры маршрута из URL.
        $result = $container->call(
            static fn (string $slug): string => $slug,
            ['slug' => 'x{db.password}y'],
        );

        $this->assertSame('x{db.password}y', $result);
    }

    /**
     * Проверим, что параметр с фигурными скобками, не соответствующими записи контейнера,
     * не приводит к ошибке резолва.
     *
     * @see Container::call()
     */
    #[Test]
    public function callDoesNotFailOnUnknownBraceExpression(): void
    {
        $container = new Container();

        $result = $container->call(
            static fn (string $slug): string => $slug,
            ['slug' => '{missing.entry|upper}'],
        );

        $this->assertSame('{missing.entry|upper}', $result);
    }

    /**
     * Проверим, что строковый runtime-параметр make() передаётся в конструктор без интерполяции.
     *
     * @see Container::make()
     */
    #[Test]
    public function makePassesConstructorParameterWithBracesAsIs(): void
    {
        $container = new Container(['db.password' => 'secret']);

        $target = $container->make(RequiredScalarTarget::class, ['name' => '{db.password}']);

        $this->assertSame('{db.password}', $target->name);
    }

    /**
     * Проверим, что обычная строка с фигурными скобками в определении остаётся значением как есть.
     *
     * @see Container::set()
     */
    #[Test]
    public function plainStringDefinitionWithBracesIsNotInterpolated(): void
    {
        $container = new Container(['id' => 'secret']);

        $container->set('route.pattern', '/users/{id}');

        $this->assertSame('/users/{id}', $container->get('route.pattern'));
    }

    /**
     * Проверим, что явный string() в параметрах call() по-прежнему интерполируется.
     *
     * @see Container::call()
     */
    #[Test]
    public function callInterpolatesExplicitStringDefinition(): void
    {
        $container = new Container(['app.name' => 'PhpSoftBox']);

        $result = $container->call(
            static fn (string $title): string => $title,
            ['title' => diString('{app.name|lower}')],
        );

        $this->assertSame('phpsoftbox', $result);
    }
}
