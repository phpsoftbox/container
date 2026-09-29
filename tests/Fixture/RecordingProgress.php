<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Tests\Fixture;

use PhpSoftBox\CliApp\Io\ProgressInterface;

final class RecordingProgress implements ProgressInterface
{
    public function advance(int $step = 1): void
    {
    }

    public function finish(): void
    {
    }
}
