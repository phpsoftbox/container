<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Tests\Fixture;

use PhpSoftBox\CliApp\Io\IoInterface;
use PhpSoftBox\CliApp\Request\Request;
use PhpSoftBox\CliApp\Response;
use PhpSoftBox\CliApp\Runner\RunnerInterface;

final readonly class StubRunner implements RunnerInterface
{
    public function __construct(
        private Request $request,
        private IoInterface $io,
    ) {
    }

    public function run(string $command, array $argv): Response
    {
        return new Response();
    }

    public function runSubCommand(string $command, array $argv): Response
    {
        return new Response();
    }

    public function request(): Request
    {
        return $this->request;
    }

    public function io(): IoInterface
    {
        return $this->io;
    }

    public function environment(): string
    {
        return 'test';
    }
}
