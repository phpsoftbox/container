<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Reset;

use LogicException;
use PhpSoftBox\Container\Container;
use Throwable;

use function is_object;
use function method_exists;
use function spl_object_id;

/**
 * Общий хук сброса состояния для воркеров: вызывается после каждой задачи или запроса.
 *
 * Сбрасываются только уже созданные контейнером сервисы — ничего не создаётся ради сброса:
 * - сервисы, реализующие {@see ResetInterface};
 * - сервисы из карты `$hooks` (запись контейнера → метод или список методов) — для классов из пакетов, которые не
 *   зависят от контейнера (например, `ConnectionManagerInterface::class => 'clearWarmup'`).
 *
 * Ошибка одного сброса не прерывает остальные: после обхода бросается первое исключение.
 */
final readonly class ServicesResetter implements ResetInterface
{
    /**
     * @param array<string, string|list<string>> $hooks
     */
    public function __construct(
        private Container $container,
        private array $hooks = [],
    ) {
    }

    public function reset(): void
    {
        $first = null;
        $done  = [];

        foreach ($this->container->resolvedInstances() as $instance) {
            $key = spl_object_id($instance);
            if ($instance === $this || isset($done[$key]) || !$instance instanceof ResetInterface) {
                continue;
            }

            $done[$key] = true;
            $first ??= $this->call(static fn () => $instance->reset());
        }

        foreach ($this->hooks as $id => $methods) {
            $instance = $this->container->resolvedInstance($id);
            if (!is_object($instance)) {
                continue;
            }

            foreach ((array) $methods as $method) {
                if (!method_exists($instance, $method)) {
                    throw new LogicException('Reset hook method ' . $instance::class . '::' . $method . '() does not exist.');
                }

                $first ??= $this->call(static fn () => $instance->{$method}());
            }
        }

        if ($first !== null) {
            throw $first;
        }
    }

    private function call(callable $reset): ?Throwable
    {
        try {
            $reset();
        } catch (Throwable $exception) {
            return $exception;
        }

        return null;
    }
}
