<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Cli;

use PhpSoftBox\CliApp\Response;
use PhpSoftBox\CliApp\Runner\RunnerInterface;

use function is_string;

final class GraphHandler extends AbstractDiagnosticsHandler
{
    public function run(RunnerInterface $runner): int|Response
    {
        $entry = $runner->request()->param('entry');
        if (!is_string($entry) || $entry === '') {
            $runner->io()->writeln('Entry id обязателен.', 'error');

            return Response::INVALID_INPUT;
        }

        $this->writeReport($runner, $this->container->diagnostics()->renderGraph($entry));

        return Response::SUCCESS;
    }
}
