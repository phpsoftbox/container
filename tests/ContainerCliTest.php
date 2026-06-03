<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Tests;

use PhpSoftBox\CliApp\Command\HandlerInterface;
use PhpSoftBox\CliApp\Command\InMemoryCommandRegistry;
use PhpSoftBox\CliApp\Io\IoInterface;
use PhpSoftBox\CliApp\Io\ProgressInterface;
use PhpSoftBox\CliApp\Request\Request;
use PhpSoftBox\CliApp\Response;
use PhpSoftBox\CliApp\Runner\RunnerInterface;
use PhpSoftBox\Container\Cli\AotHandler;
use PhpSoftBox\Container\Cli\ContainerCommandProvider;
use PhpSoftBox\Container\Cli\GraphHandler;
use PhpSoftBox\Container\Cli\ValidateHandler;
use PhpSoftBox\Container\Container;
use PhpSoftBox\Container\Tests\Fixture\ServiceWithDependency;
use PhpSoftBox\Container\Tests\Fixture\SimpleDependency;
use PHPUnit\Framework\TestCase;

use function implode;
use function PhpSoftBox\Container\autowire;
use function PhpSoftBox\Container\get;

final class ContainerCliTest extends TestCase
{
    public function testCommandProviderRegistersContainerCommands(): void
    {
        $registry = new InMemoryCommandRegistry(false);

        new ContainerCommandProvider()->register($registry);

        $validate = $registry->get('container:validate');
        $graph    = $registry->get('container:graph');
        $aot      = $registry->get('container:aot');

        $this->assertNotNull($validate);
        $this->assertSame(ValidateHandler::class, $validate->handler);
        $this->assertSame('entry', $validate->signature->options()['entry']->name);
        $this->assertSame('i', $validate->signature->options()['entry']->short);
        $this->assertTrue($validate->signature->options()['entry']->repeatable);

        $this->assertNotNull($graph);
        $this->assertSame(GraphHandler::class, $graph->handler);
        $this->assertSame('entry', $graph->signature->arguments()[0]->name);

        $this->assertNotNull($aot);
        $this->assertSame(AotHandler::class, $aot->handler);
        $this->assertSame('entry', $aot->signature->arguments()[0]->name);
        $this->assertFalse($aot->signature->arguments()[0]->required);
    }

    public function testValidateCommandWritesValidationReport(): void
    {
        $container = new Container([
            'missing.alias' => get('missing.entry'),
        ], false);

        [$code, $output] = $this->runHandler(
            new ValidateHandler($container),
            new Request([], ['entry' => ['missing.alias']]),
        );

        $this->assertSame(Response::FAILURE, $code);
        $this->assertStringContainsString('Validation: failed', $output);
        $this->assertStringContainsString('[not-found] missing.entry', $output);
    }

    public function testGraphCommandWritesDependencyGraph(): void
    {
        $container = new Container([
            ServiceWithDependency::class => autowire(ServiceWithDependency::class),
            SimpleDependency::class      => autowire(SimpleDependency::class),
        ]);

        [$code, $output] = $this->runHandler(
            new GraphHandler($container),
            new Request(['entry' => ServiceWithDependency::class], []),
        );

        $this->assertSame(Response::SUCCESS, $code);
        $this->assertStringContainsString('Dependency graph: ' . ServiceWithDependency::class, $output);
        $this->assertStringContainsString('constructor ' . SimpleDependency::class, $output);
    }

    public function testAotCommandWritesPlanForSelectedEntry(): void
    {
        $container = new Container([
            'answer' => 42,
        ]);

        [$code, $output] = $this->runHandler(
            new AotHandler($container),
            new Request(['entry' => 'answer'], []),
        );

        $this->assertSame(Response::SUCCESS, $code);
        $this->assertStringContainsString('AOT plan:', $output);
        $this->assertStringContainsString('- answer: eligible (value)', $output);
    }

    /**
     * @return array{0:int,1:string}
     */
    private function runHandler(HandlerInterface $handler, Request $request): array
    {
        $io = new RecordingIo();

        $response = $handler->run(new StubRunner($request, $io));

        return [
            $response instanceof Response ? $response->code : $response,
            $io->output(),
        ];
    }
}

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

final class RecordingProgress implements ProgressInterface
{
    public function advance(int $step = 1): void
    {
    }

    public function finish(): void
    {
    }
}

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
}
