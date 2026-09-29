<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Tests;

use PhpSoftBox\Container\Compilation\AtomicFileWriter;
use PhpSoftBox\Container\Compilation\CompiledCacheStorage;
use PhpSoftBox\Container\ContainerBuilder;
use PhpSoftBox\Container\Tests\Fixture\SimpleDependency;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function file_put_contents;
use function glob;
use function is_dir;
use function mkdir;
use function PhpSoftBox\Container\autowire;
use function random_bytes;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function time;
use function touch;
use function unlink;

#[CoversClass(ContainerBuilder::class)]
#[CoversClass(CompiledCacheStorage::class)]
#[CoversMethod(ContainerBuilder::class, 'clearCompiledCache')]
#[CoversMethod(CompiledCacheStorage::class, 'clear')]
final class CompiledCacheClearTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/psb_container_clear_' . bin2hex(random_bytes(4));
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
     * Проверим, что статический сброс кеша по каталогу удаляет lazy-метаданные и все сгенерированные
     * классы контейнера без сборки определений.
     *
     * @see ContainerBuilder::clearCompiledCache()
     */
    #[Test]
    public function clearCompiledCacheRemovesCompiledFiles(): void
    {
        new ContainerBuilder([SimpleDependency::class => autowire()])
            ->enableCompilation($this->directory)
            ->build();

        $this->assertFileExists($this->directory . '/' . CompiledCacheStorage::FILENAME);
        $this->assertCount(1, glob($this->directory . '/CompiledContainer_*.php') ?: []);

        $removed = ContainerBuilder::clearCompiledCache($this->directory);

        $this->assertSame(2, $removed);
        $this->assertFileDoesNotExist($this->directory . '/' . CompiledCacheStorage::FILENAME);
        $this->assertSame([], glob($this->directory . '/CompiledContainer_*.php') ?: []);
    }

    /**
     * Проверим, что сброс кеша для несуществующего каталога не считается ошибкой.
     *
     * @see ContainerBuilder::clearCompiledCache()
     */
    #[Test]
    public function clearCompiledCacheIgnoresMissingDirectory(): void
    {
        $this->assertSame(0, ContainerBuilder::clearCompiledCache($this->directory));
    }

    /**
     * Проверим, что сброс удаляет брошенные временные файлы атомарной записи.
     *
     * @see CompiledCacheStorage::clear()
     */
    #[Test]
    public function clearRemovesStaleTemporaryFiles(): void
    {
        mkdir($this->directory, 0775, true);
        $tmpFile = $this->directory . '/' . AtomicFileWriter::TEMP_PREFIX . 'stale';
        file_put_contents($tmpFile, '<?php');
        touch($tmpFile, time() - 3600);

        $removed = new CompiledCacheStorage($this->directory)->clear();

        $this->assertSame(1, $removed);
        $this->assertFileDoesNotExist($tmpFile);
    }

    /**
     * Проверим, что сброс не трогает свежий временный файл, который может дописывать параллельный воркер.
     *
     * @see CompiledCacheStorage::clear()
     */
    #[Test]
    public function clearKeepsFreshTemporaryFiles(): void
    {
        mkdir($this->directory, 0775, true);
        $tmpFile = $this->directory . '/' . AtomicFileWriter::TEMP_PREFIX . 'fresh';
        file_put_contents($tmpFile, '<?php');

        $removed = new CompiledCacheStorage($this->directory)->clear();

        $this->assertSame(0, $removed);
        $this->assertFileExists($tmpFile);
    }
}
