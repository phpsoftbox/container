<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Cli;

use PhpSoftBox\CliApp\Response;
use PhpSoftBox\CliApp\Runner\RunnerInterface;
use PhpSoftBox\Container\Diagnostics\DiagnosticsReportFormatter;

use function is_string;

final class AotHandler extends AbstractDiagnosticsHandler
{
    public function run(RunnerInterface $runner): int|Response
    {
        $entry = $runner->request()->param('entry');
        if ($entry !== null && (!is_string($entry) || $entry === '')) {
            $runner->io()->writeln('Некорректный id entry.', 'error');

            return Response::INVALID_INPUT;
        }

        $diagnostics = $this->container->diagnostics();
        if ($entry === null) {
            $this->writeReport($runner, $diagnostics->renderAotPlan());

            return Response::SUCCESS;
        }

        $report = new DiagnosticsReportFormatter()->aotPlan([
            $entry => $diagnostics->aot($entry),
        ]);

        $this->writeReport($runner, $report);

        return Response::SUCCESS;
    }
}
