<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Definition;

use PhpSoftBox\Container\Container;
use PhpSoftBox\Container\Exception\ContainerException;
use Throwable;

final readonly class DecoratorDefinition
{
    public function __construct(
        private mixed $decorator,
    ) {
    }

    public function decorator(): mixed
    {
        return $this->decorator;
    }

    /**
     * @param array<string|int, mixed> $parameters
     */
    public function decorate(
        Container $container,
        string $id,
        mixed $previous,
        array $parameters = [],
    ): mixed {
        $callable = $container->resolveCallableFactory($this->decorator);

        try {
            return $container->call(
                $callable,
                [
                    0            => $previous,
                    1            => $container,
                    'id'         => $id,
                    'name'       => $id,
                    'entry'      => $id,
                    'previous'   => $previous,
                    'container'  => $container,
                    'parameters' => $parameters,
                ],
            );
        } catch (Throwable $exception) {
            throw new ContainerException(
                'Failed to decorate entry "' . $id . '": ' . $exception->getMessage(),
                0,
                $exception,
            );
        }
    }
}
