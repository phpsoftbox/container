<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Compilation;

use RuntimeException;
use Throwable;

use function dirname;
use function file_exists;
use function file_put_contents;
use function glob;
use function is_array;
use function is_dir;
use function is_string;
use function mkdir;
use function rename;
use function tempnam;
use function unlink;
use function var_export;

use const LOCK_EX;

final class CompiledCacheStorage
{
    public const SCHEMA   = 1;
    public const FILENAME = 'container.compiled.php';

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

        $export    = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($payload, true) . ";\n";
        $cacheFile = $this->cacheFile();
        $tmpFile   = tempnam(dirname($cacheFile), 'container_cache_');
        if ($tmpFile === false) {
            throw new RuntimeException('Failed to create temporary cache file in: ' . dirname($cacheFile));
        }

        try {
            if (file_put_contents($tmpFile, $export, LOCK_EX) === false) {
                throw new RuntimeException('Failed to write compiled container cache file: ' . $cacheFile);
            }

            if (!rename($tmpFile, $cacheFile)) {
                throw new RuntimeException('Failed to move compiled cache into place: ' . $cacheFile);
            }
        } catch (Throwable $exception) {
            @unlink($tmpFile);

            throw $exception;
        }
    }

    public function clear(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }

        @unlink($this->cacheFile());

        foreach (glob($this->directory . '/CompiledContainer_*.php') ?: [] as $file) {
            @unlink($file);
        }
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
