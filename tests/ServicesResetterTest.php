<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Tests;

use LogicException;
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
            'following'               => factory(static fn (): WarmupCache => new WarmupCache()),
            CountingResettable::class => factory(static fn (): CountingResettable => new CountingResettable()),
        ]);

        $container->get('failing');
        $following  = $container->get('following');
        $resettable = $container->get(CountingResettable::class);

        try {
            new ServicesResetter($container, [
                'failing'   => 'clearWarmup',
                'following' => 'clearWarmup',
            ])->reset();
            self::fail('Exception expected.');
        } catch (RuntimeException $exception) {
            self::assertSame('Warmup reset failed.', $exception->getMessage());
        }

        self::assertSame(1, $resettable->resets);
        self::assertSame(1, $following->clears);
    }

    /**
     * Проверим, что несуществующий метод сохраняет ошибку, но не прерывает следующие хуки того же и других сервисов.
     *
     * @see ServicesResetter::reset()
     */
    #[Test]
    public function continuesAfterMissingHookMethod(): void
    {
        $container = new Container([
            'first'  => factory(static fn (): WarmupCache => new WarmupCache()),
            'second' => factory(static fn (): WarmupCache => new WarmupCache()),
        ]);

        $first  = $container->get('first');
        $second = $container->get('second');

        try {
            new ServicesResetter($container, [
                'first'  => ['missing', 'clearWarmup'],
                'second' => 'clearWarmup',
            ])->reset();
            self::fail('Exception expected.');
        } catch (LogicException $exception) {
            self::assertSame(
                'Reset hook method ' . WarmupCache::class . '::missing() does not exist.',
                $exception->getMessage(),
            );
        }

        self::assertSame(1, $first->clears);
        self::assertSame(1, $second->clears);
    }

    /**
     * Проверим, что ошибка ResetInterface не мешает хукам, а после обхода выбрасывается первой.
     *
     * @see ServicesResetter::reset()
     * @see CountingResettable::reset()
     */
    #[Test]
    public function continuesAfterFailedResetInterfaceService(): void
    {
        $container = new Container([
            CountingResettable::class => factory(static fn (): CountingResettable => new CountingResettable(fail: true)),
            WarmupCache::class        => factory(static fn (): WarmupCache => new WarmupCache()),
        ]);

        $resettable = $container->get(CountingResettable::class);
        $warmup     = $container->get(WarmupCache::class);

        try {
            new ServicesResetter($container, [WarmupCache::class => 'clearWarmup'])->reset();
            self::fail('Exception expected.');
        } catch (RuntimeException $exception) {
            self::assertSame('Service reset failed.', $exception->getMessage());
        }

        self::assertSame(1, $resettable->resets);
        self::assertSame(1, $warmup->clears);
    }
}
