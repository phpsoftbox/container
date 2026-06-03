<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Cli;

use PhpSoftBox\CliApp\Response;
use PhpSoftBox\CliApp\Runner\RunnerInterface;
use PhpSoftBox\Container\Diagnostics\DiagnosticsReportFormatter;

final class ValidateHandler extends AbstractDiagnosticsHandler
{
    public function run(RunnerInterface $runner): int|Response
    {
        $entries = $this->normalizeEntries($runner->request()->option('entry', []));
        $report  = $this->container->validate($entries);

        $this->writeReport(
            $runner,
            new DiagnosticsReportFormatter()->validation($report),
            $report['valid'] ? 'success' : 'error',
        );

        return $report['valid'] ? Response::SUCCESS : Response::FAILURE;
    }
}
