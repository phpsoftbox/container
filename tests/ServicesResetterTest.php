<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Tests;

use PhpSoftBox\Container\Container;
use PhpSoftBox\Container\Reset\ServicesResetter;
use PhpSoftBox\Container\Tests\Fixture\Reset\CountingResettable;
use PhpSoftBox\Container\Tests\Fixture\Reset\WarmupCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function PhpSoftBox\Container\factory;

#[CoversClass(ServicesResetter::class)]
#[CoversMethod(ServicesResetter::class, 'reset')]
#[CoversMethod(Container::class, 'resolvedInstances')]
final class ServicesResetterTest extends TestCase
{
    /**
     * Проверим, что сбрасываются созданные сервисы с ResetInterface и сервисы из карты хуков, а несозданные — не
     * создаются ради сброса.
     *
     * @see ServicesResetter::reset()
     */
    #[Test]
    public function resetsOnlyCreatedServices(): void
    {
        $container = new Container([
            CountingResettable::class => factory(static fn (): CountingResettable => new CountingResettable()),
            WarmupCache::class        => factory(static fn (): WarmupCache => new WarmupCache()),
            'lazy.warmup'             => factory(static fn (): never => throw new RuntimeException('Must not be created.')),
        ]);

        $resettable = $container->get(CountingResettable::class);
        $warmup     = $container->get(WarmupCache::class);

        new ServicesResetter($container, [WarmupCache::class => 'clearWarmup', 'lazy.warmup' => 'clearWarmup'])->reset();

        self::assertSame(1, $resettable->resets);
        self::assertSame(1, $warmup->clears);
    }

    /**
     * Проверим, что ошибка одного сброса не прерывает остальные, а исключение бросается после обхода.
     *
     * @see ServicesResetter::reset()
     */
    #[Test]
    public function continuesAfterFailedReset(): void
    {
        $container = new Container([
            'failing'                 => factory(static fn (): WarmupCache => new WarmupCache(fail: true)),
            CountingResettable::class => factory(static fn (): CountingResettable => new CountingResettable()),
        ]);

        $container->get('failing');
        $resettable = $container->get(CountingResettable::class);

        try {
            new ServicesResetter($container, ['failing' => 'clearWarmup'])->reset();
            self::fail('Exception expected.');
        } catch (RuntimeException $exception) {
            self::assertSame('Warmup reset failed.', $exception->getMessage());
        }

        self::assertSame(1, $resettable->resets);
    }
}
