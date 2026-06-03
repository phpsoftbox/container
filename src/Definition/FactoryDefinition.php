<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Definition;

use PhpSoftBox\Container\Container;
use PhpSoftBox\Container\Exception\ContainerException;
use PhpSoftBox\Container\Factory\RequestedEntry;
use Throwable;

final readonly class FactoryDefinition implements DefinitionInterface
{
    public function __construct(
        private mixed $factory,
    ) {
    }

    public function factory(): mixed
    {
        return $this->factory;
    }

    public function resolve(Container $container, string $id, array $parameters = [], bool $fresh = false): mixed
    {
        $callable = $container->resolveCallableFactory($this->factory);

        try {
            return $container->call(
                $callable,
                [
                    ...$parameters,
                    'requestedEntry' => new RequestedEntry($id),
                    'container'      => $container,
                ],
            );
        } catch (Throwable $exception) {
            throw new ContainerException(
                'Failed to resolve factory for entry "' . $id . '": ' . $exception->getMessage(),
                0,
                $exception,
            );
        }
    }
}
