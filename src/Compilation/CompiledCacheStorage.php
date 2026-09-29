<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Compilation;

use RuntimeException;
use Throwable;

use function file_exists;
use function filemtime;
use function glob;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function mkdir;
use function time;
use function unlink;
use function var_export;

final class CompiledCacheStorage
{
    public const SCHEMA   = 1;
    public const FILENAME = 'container.compiled.php';

    private const STALE_TEMP_FILE_TTL = 60;

    public function __construct(
        private readonly string $directory,
    ) {
    }

    public function ensureDirectory(): void
    {
        if (is_dir($this->directory)) {
            return;
        }

        if (!@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Failed to create compilation directory: ' . $this->directory);
        }
    }

    /**
     * @return array<string, string>|null
     */
    public function read(string $fingerprint): ?array
    {
        $cacheFile = $this->cacheFile();
        if (!file_exists($cacheFile)) {
            return null;
        }

        try {
            $payload = require $cacheFile;
        } catch (Throwable) {
            return null;
        }

        if (!is_array($payload)) {
            return null;
        }

        if (($payload['schema'] ?? null) !== self::SCHEMA) {
            return null;
        }

        if (($payload['fingerprint'] ?? null) !== $fingerprint) {
            return null;
        }

        $lazyEntries = $payload['lazyEntries'] ?? null;
        if (!$this->isValidLazyEntries($lazyEntries)) {
            return null;
        }

        return $lazyEntries;
    }

    /**
     * @param array<string, string> $lazyEntries
     * @param array<string, array{internal: bool, instantiable: bool}> $lazyClassMetadata
     */
    public function write(string $fingerprint, array $lazyEntries, array $lazyClassMetadata): void
    {
        $payload = [
            'schema'            => self::SCHEMA,
            'fingerprint'       => $fingerprint,
            'lazyEntries'       => $lazyEntries,
            'lazyClassMetadata' => $lazyClassMetadata,
        ];

        $export = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($payload, true) . ";\n";

        AtomicFileWriter::write($this->cacheFile(), $export);
    }

    /**
     * Удаляет все файлы кеша компиляции в каталоге: lazy-метаданные, сгенерированные классы контейнера
     * (всех fingerprint-ов) и оставшиеся временные файлы. Возвращает количество удалённых файлов.
     */
    public function clear(): int
    {
        if (!is_dir($this->directory)) {
            return 0;
        }

        $files = [
            $this->cacheFile(),
            ...(glob($this->directory . '/CompiledContainer_*.php') ?: []),
        ];

        // Свежие временные файлы могут принадлежать воркеру, который прямо сейчас пишет кеш:
        // удаляем только заведомо брошенные.
        foreach (glob($this->directory . '/' . AtomicFileWriter::TEMP_PREFIX . '*') ?: [] as $tmpFile) {
            if ((int) @filemtime($tmpFile) < time() - self::STALE_TEMP_FILE_TTL) {
                $files[] = $tmpFile;
            }
        }

        $removed = 0;
        foreach ($files as $file) {
            if (is_file($file) && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    private function cacheFile(): string
    {
        return $this->directory . '/' . self::FILENAME;
    }

    private function isValidLazyEntries(mixed $lazyEntries): bool
    {
        if (!is_array($lazyEntries)) {
            return false;
        }

        foreach ($lazyEntries as $id => $className) {
            if (!is_string($id) || !is_string($className) || $className === '') {
                return false;
            }
        }

        return true;
    }
}
