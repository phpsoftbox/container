<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Tests;

use PhpSoftBox\Container\Compilation\AtomicFileWriter;
use PhpSoftBox\Container\ContainerBuilder;
use PhpSoftBox\Container\Tests\Fixture\SimpleDependency;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function clearstatcache;
use function file_get_contents;
use function fileperms;
use function glob;
use function is_dir;
use function mkdir;
use function PhpSoftBox\Container\autowire;
use function random_bytes;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function umask;
use function unlink;

#[CoversClass(AtomicFileWriter::class)]
#[CoversClass(ContainerBuilder::class)]
#[CoversMethod(AtomicFileWriter::class, 'write')]
#[CoversMethod(ContainerBuilder::class, 'build')]
final class AtomicFileWriterTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/psb_container_atomic_' . bin2hex(random_bytes(4));
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
     * Проверим, что запись заменяет содержимое существующего файла и не оставляет временных файлов.
     *
     * @see AtomicFileWriter::write()
     */
    #[Test]
    public function writeReplacesFileWithoutTemporaryLeftovers(): void
    {
        mkdir($this->directory, 0775, true);
        $file = $this->directory . '/target.php';

        AtomicFileWriter::write($file, 'first');
        AtomicFileWriter::write($file, 'second');

        $this->assertSame('second', file_get_contents($file));
        $this->assertSame([], glob($this->directory . '/' . AtomicFileWriter::TEMP_PREFIX . '*') ?: []);
    }

    /**
     * Проверим, что итоговый файл получает права обычного файла с учётом umask, а не 0600 от tempnam().
     *
     * @see AtomicFileWriter::write()
     */
    #[Test]
    public function writeAppliesDefaultFilePermissions(): void
    {
        mkdir($this->directory, 0775, true);
        $file = $this->directory . '/target.php';

        AtomicFileWriter::write($file, 'content');
        clearstatcache();

        $this->assertSame(0666 & ~umask(), fileperms($file) & 0777);
    }

    /**
     * Проверим, что сборка скомпилированного контейнера пишет класс без временных файлов в каталоге кеша.
     *
     * @see ContainerBuilder::build()
     */
    #[Test]
    public function buildWritesCompiledClassWithoutTemporaryLeftovers(): void
    {
        new ContainerBuilder([SimpleDependency::class => autowire()])
            ->enableCompilation($this->directory)
            ->build();

        $this->assertCount(1, glob($this->directory . '/CompiledContainer_*.php') ?: []);
        $this->assertSame([], glob($this->directory . '/' . AtomicFileWriter::TEMP_PREFIX . '*') ?: []);
    }
}
