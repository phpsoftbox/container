<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Tests;

use PhpSoftBox\Container\Container;
use PhpSoftBox\Container\Tests\Fixture\CallableTarget;
use PhpSoftBox\Container\Tests\Fixture\StaticCallableTarget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WeakReference;

use function gc_collect_cycles;

#[CoversClass(Container::class)]
#[CoversMethod(Container::class, 'call')]
final class CallableReflectionCacheTest extends TestCase
{
    /**
     * Проверим, что после call() контейнер не удерживает переданное замыкание (нет утечки памяти
     * в кеше рефлексии при многократных вызовах с новыми замыканиями).
     *
     * @see Container::call()
     */
    #[Test]
    public function callDoesNotRetainClosure(): void
    {
        $container = new Container();
        $closure   = static fn (int $value): int => $value * 2;
        $reference = WeakReference::create($closure);

        $this->assertSame(42, $container->call($closure, ['value' => 21]));

        // После удаления единственной внешней ссылки замыкание должно быть собрано.
        unset($closure);
        gc_collect_cycles();

        $this->assertNull($reference->get());
    }

    /**
     * Проверим, что после call([$object, 'method']) контейнер не удерживает объект.
     *
     * @see Container::call()
     */
    #[Test]
    public function callDoesNotRetainArrayCallableTarget(): void
    {
        $container = new Container();
        $target    = new CallableTarget();

        $reference = WeakReference::create($target);

        $this->assertSame('7:dep', $container->call([$target, 'handle'], ['id' => '7']));

        unset($target);
        gc_collect_cycles();

        $this->assertNull($reference->get());
    }

    /**
     * Проверим, что кеш рефлексии по классу корректно обслуживает разные экземпляры одного класса.
     *
     * @see Container::call()
     */
    #[Test]
    public function callInvokesMethodOnEachInstanceOfSameClass(): void
    {
        $container = new Container();

        $first  = $container->call([new CallableTarget(), 'handle'], ['id' => 'first']);
        $second = $container->call([new CallableTarget(), 'handle'], ['id' => 'second']);

        $this->assertSame('first:dep', $first);
        $this->assertSame('second:dep', $second);
    }

    /**
     * Проверим, что статический метод в виде строки "Class::method" вызывается через call().
     *
     * @see Container::call()
     */
    #[Test]
    public function callSupportsStaticMethodString(): void
    {
        $container = new Container();

        $result = $container->call(StaticCallableTarget::class . '::greet', ['name' => 'world']);

        $this->assertSame('hello world', $result);
    }
}
