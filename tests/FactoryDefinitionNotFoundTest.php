<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Tests;

use PhpSoftBox\Container\Container;
use PhpSoftBox\Container\ContainerBuilder;
use PhpSoftBox\Container\Definition\FactoryDefinition;
use PhpSoftBox\Container\Exception\ContainerException;
use PhpSoftBox\Container\Exception\NotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

use function bin2hex;
use function PhpSoftBox\Container\factory;
use function random_bytes;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(FactoryDefinition::class)]
#[CoversClass(Container::class)]
#[CoversMethod(FactoryDefinition::class, 'resolve')]
#[CoversMethod(Container::class, 'get')]
final class FactoryDefinitionNotFoundTest extends TestCase
{
    private ?string $directory = null;

    protected function tearDown(): void
    {
        if ($this->directory === null) {
            return;
        }

        foreach (scandir($this->directory) ?: [] as $file) {
            if ($file !== '.' && $file !== '..') {
                @unlink($this->directory . '/' . $file);
            }
        }

        @rmdir($this->directory);
    }

    /**
     * Проверим, что NotFound из фабрики (отсутствующая запись) не превращается в общий ContainerException:
     * исключение остаётся NotFoundExceptionInterface, содержит контекст записи и исходную причину.
     *
     * @see FactoryDefinition::resolve()
     * @see Container::get()
     */
    #[Test]
    public function factoryMissingEntryKeepsNotFoundException(): void
    {
        $container = new Container([
            'mailer' => factory(static fn (ContainerInterface $container): mixed => $container->get('mailer.transport')),
        ]);

        try {
            $container->get('mailer');
            $this->fail('NotFound exception expected.');
        } catch (NotFoundExceptionInterface $exception) {
            $this->assertInstanceOf(NotFoundException::class, $exception);
            $this->assertStringContainsString('Failed to resolve factory for entry "mailer"', $exception->getMessage());
            $this->assertStringContainsString('mailer.transport', $exception->getMessage());
            $this->assertInstanceOf(NotFoundException::class, $exception->getPrevious());
        }
    }

    /**
     * Проверим, что прочие ошибки фабрики по-прежнему оборачиваются в ContainerException, а не в NotFound.
     *
     * @see FactoryDefinition::resolve()
     */
    #[Test]
    public function factoryFailureIsWrappedIntoContainerException(): void
    {
        $container = new Container([
            'broken' => factory(static fn (): never => throw new RuntimeException('boom')),
        ]);

        try {
            $container->get('broken');
            $this->fail('Container exception expected.');
        } catch (ContainerException $exception) {
            $this->assertNotInstanceOf(NotFoundExceptionInterface::class, $exception);
            $this->assertInstanceOf(RuntimeException::class, $exception->getPrevious());
        }
    }

    /**
     * Проверим, что скомпилированный (AOT) путь фабрики сохраняет NotFound так же, как runtime.
     *
     * @see FactoryDefinition::resolve()
     * @see Container::get()
     */
    #[Test]
    public function compiledFactoryMissingEntryKeepsNotFoundException(): void
    {
        $this->directory = sys_get_temp_dir() . '/psb_container_factory_' . bin2hex(random_bytes(4));

        $container = new ContainerBuilder([
            'mailer' => factory(static fn (ContainerInterface $container): mixed => $container->get('mailer.transport')),
        ])->enableCompilation($this->directory)->build();

        $this->assertSame('factory', $container->__compiledEntries()['mailer'] ?? null);

        $this->expectException(NotFoundExceptionInterface::class);
        $this->expectExceptionMessage('Failed to resolve factory for entry "mailer"');

        $container->get('mailer');
    }
}
