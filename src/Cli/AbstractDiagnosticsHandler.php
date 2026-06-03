<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Cli;

use PhpSoftBox\CliApp\Command\HandlerInterface;
use PhpSoftBox\CliApp\Runner\RunnerInterface;
use PhpSoftBox\Container\Container;

use function explode;
use function is_array;
use function is_string;

abstract class AbstractDiagnosticsHandler implements HandlerInterface
{
    public function __construct(
        protected readonly Container $container,
    ) {
    }

    /**
     * @return list<string>
     */
    protected function normalizeEntries(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            return [$value];
        }

        if (!is_array($value)) {
            return [];
        }

        $entries = [];
        foreach ($value as $entry) {
            foreach ($this->normalizeEntries($entry) as $normalizedEntry) {
                $entries[] = $normalizedEntry;
            }
        }

        return $entries;
    }

    protected function writeReport(RunnerInterface $runner, string $report, string $style = 'info'): void
    {
        foreach (explode("\n", $report) as $line) {
            $runner->io()->writeln($line, $style);
        }
    }
}
