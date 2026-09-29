<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Compilation;

use RuntimeException;
use Throwable;

use function chmod;
use function dirname;
use function file_put_contents;
use function rename;
use function tempnam;
use function umask;
use function unlink;

/**
 * Атомарная запись файлов кеша компиляции: содержимое пишется во временный файл в том же каталоге
 * и переносится на итоговое имя через rename(). Параллельный воркер видит либо старый файл, либо
 * полностью записанный новый, но никогда не недописанный.
 */
final class AtomicFileWriter
{
    /**
     * Префикс временных файлов; по нему CompiledCacheStorage::clear() удаляет «осиротевшие» файлы.
     */
    public const TEMP_PREFIX = '.psb_container_tmp_';

    public static function write(string $file, string $contents): void
    {
        $directory = dirname($file);
        $tmpFile   = tempnam($directory, self::TEMP_PREFIX);
        if ($tmpFile === false) {
            throw new RuntimeException('Failed to create temporary file in: ' . $directory);
        }

        try {
            if (file_put_contents($tmpFile, $contents) === false) {
                throw new RuntimeException('Failed to write temporary file for: ' . $file);
            }

            // tempnam() создаёт файл с правами 0600; выравниваем права как у обычного файла, чтобы кеш,
            // прогретый из CLI, читался воркерами веб-сервера.
            @chmod($tmpFile, 0666 & ~umask());

            if (!rename($tmpFile, $file)) {
                throw new RuntimeException('Failed to move temporary file into place: ' . $file);
            }
        } catch (Throwable $exception) {
            @unlink($tmpFile);

            throw $exception;
        }
    }
}
