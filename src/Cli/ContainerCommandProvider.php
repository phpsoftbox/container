<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Cli;

use PhpSoftBox\CliApp\Command\ArgumentDefinition;
use PhpSoftBox\CliApp\Command\Command;
use PhpSoftBox\CliApp\Command\CommandRegistryInterface;
use PhpSoftBox\CliApp\Command\OptionDefinition;
use PhpSoftBox\CliApp\Loader\CommandProviderInterface;

final class ContainerCommandProvider implements CommandProviderInterface
{
    public function register(CommandRegistryInterface $registry): void
    {
        $registry->register(Command::define(
            name: 'container:validate',
            description: 'Проверить dependency graph контейнера',
            signature: [
                new OptionDefinition(
                    name: 'entry',
                    short: 'i',
                    description: 'Entry id для проверки; можно указывать несколько раз',
                    required: false,
                    default: [],
                    type: 'string',
                    repeatable: true,
                ),
            ],
            handler: ValidateHandler::class,
        ));

        $registry->register(Command::define(
            name: 'container:graph',
            description: 'Показать dependency graph entry',
            signature: [
                new ArgumentDefinition(
                    name: 'entry',
                    description: 'Entry id',
                    required: true,
                    type: 'string',
                ),
            ],
            handler: GraphHandler::class,
        ));

        $registry->register(Command::define(
            name: 'container:aot',
            description: 'Показать AOT plan контейнера или одного entry',
            signature: [
                new ArgumentDefinition(
                    name: 'entry',
                    description: 'Entry id',
                    required: false,
                    default: null,
                    type: 'string',
                ),
            ],
            handler: AotHandler::class,
        ));
    }
}
