<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Tests\Fixture;

use PhpSoftBox\CliApp\Io\IoInterface;
use PhpSoftBox\CliApp\Io\ProgressInterface;

use function implode;

final class RecordingIo implements IoInterface
{
    /** @var list<string> */
    private array $lines = [];

    public function ask(string $question, ?string $default = null): string
    {
        return $default ?? '';
    }

    public function confirm(string $question, bool $default = false): bool
    {
        return $default;
    }

    public function secret(string $question): string
    {
        return '';
    }

    public function writeln(string $message, string $style = 'info'): void
    {
        $this->lines[] = $message;
    }

    public function table(array $headers, array $rows): void
    {
    }

    public function progress(int $max): ProgressInterface
    {
        return new RecordingProgress();
    }

    public function output(): string
    {
        return implode("\n", $this->lines);
    }
}
