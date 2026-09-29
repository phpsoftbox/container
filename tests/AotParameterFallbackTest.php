<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Tests;

use PhpSoftBox\Container\Compilation\AotContainerClassGenerator;
use PhpSoftBox\Container\Container;
use PhpSoftBox\Container\ContainerBuilder;
use PhpSoftBox\Container\Tests\Fixture\OptionalAbstractDependencyTarget;
use PhpSoftBox\Container\Tests\Fixture\OptionalRepositoryTarget;
use PhpSoftBox\Container\Tests\Fixture\UserDoctrineRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function is_dir;
use function method_exists;
use function PhpSoftBox\Container\autowire;
use function PhpSoftBox\Container\create;
use function random_bytes;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(AotContainerClassGenerator::class)]
#[CoversClass(ContainerBuilder::class)]
#[CoversClass(Container::class)]
#[CoversMethod(AotContainerClassGenerator::class, 'render')]
#[CoversMethod(ContainerBuilder::class, 'build')]
final class AotParameterFallbackTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/psb_container_aot_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
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
     * Проверим, что для необязательного параметра с абстрактным классом AOT, как и runtime,
     * подставляет значение по умолчанию, а не пытается автоматически создать абстрактный класс.
     *
     * @see ContainerBuilder::build()
     * @see AotContainerClassGenerator::render()
     */
    #[Test]
    public function compiledContainerUsesDefaultForNonInstantiableClassType(): void
    {
        $definitions = [OptionalAbstractDependencyTarget::class => autowire()];

        $compiled = $this->compiledBuilder($definitions)->build();
        $runtime  = new ContainerBuilder($definitions)->build();

        // Убедимся, что запись действительно идёт через AOT fast-path.
        $this->assertTrue(method_exists($compiled, '__compiledEntries'));
        $this->assertSame('object', $compiled->__compiledEntries()[OptionalAbstractDependencyTarget::class] ?? null);

        $this->assertNull($runtime->get(OptionalAbstractDependencyTarget::class)->dependency);
        $this->assertNull($compiled->get(OptionalAbstractDependencyTarget::class)->dependency);
    }

    /**
     * Проверим, что необязательный параметр-интерфейс, покрытый wildcard-определением, AOT резолвит
     * так же, как runtime, а не подставляет значение по умолчанию.
     *
     * @see ContainerBuilder::build()
     * @see AotContainerClassGenerator::render()
     */
    #[Test]
    public function compiledContainerResolvesWildcardTypeLikeRuntime(): void
    {
        $definitions = [
            OptionalRepositoryTarget::class                               => autowire(),
            'PhpSoftBox\\Container\\Tests\\Fixture\\*RepositoryInterface' => create(
                'PhpSoftBox\\Container\\Tests\\Fixture\\*DoctrineRepository',
            ),
        ];

        $compiled = $this->compiledBuilder($definitions)->build();
        $runtime  = new ContainerBuilder($definitions)->build();

        $this->assertSame('object', $compiled->__compiledEntries()[OptionalRepositoryTarget::class] ?? null);

        $this->assertInstanceOf(UserDoctrineRepository::class, $runtime->get(OptionalRepositoryTarget::class)->repository);
        $this->assertInstanceOf(UserDoctrineRepository::class, $compiled->get(OptionalRepositoryTarget::class)->repository);
    }

    /**
     * @param array<string, mixed> $definitions
     */
    private function compiledBuilder(array $definitions): ContainerBuilder
    {
        return new ContainerBuilder($definitions)->enableCompilation($this->directory);
    }
}
