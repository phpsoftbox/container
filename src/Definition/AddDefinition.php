<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Definition;

use PhpSoftBox\Container\Container;
use PhpSoftBox\Container\Exception\ContainerException;

use function array_is_list;
use function array_key_exists;
use function array_merge;
use function in_array;
use function is_array;
use function is_int;

final readonly class AddDefinition implements DefinitionInterface
{
    public const MERGE_SHALLOW = 'shallow';
    public const MERGE_DEEP    = 'deep';

    /**
     * @param array<int|string, mixed> $items
     */
    public function __construct(
        private array $items,
        private string $strategy = self::MERGE_SHALLOW,
    ) {
        self::assertValidStrategy($strategy);
    }

    /**
     * @return array<int|string, mixed>
     */
    public function items(): array
    {
        return $this->items;
    }

    public function strategy(): string
    {
        return $this->strategy;
    }

    public function resolve(Container $container, string $id, array $parameters = [], bool $fresh = false): mixed
    {
        if (!$container->hasBaseDefinition($id)) {
            return $this->items;
        }

        $base = $container->resolveBaseDefinition($id, $parameters, $fresh);
        if (!is_array($base)) {
            throw new ContainerException('add() can be applied only to array entries: ' . $id);
        }

        return self::merge($base, $this->items, $this->strategy);
    }

    /**
     * @param array<int|string, mixed> $base
     * @param array<int|string, mixed> $items
     *
     * @return array<int|string, mixed>
     */
    public static function merge(array $base, array $items, string $strategy): array
    {
        self::assertValidStrategy($strategy);

        if ($strategy === self::MERGE_SHALLOW) {
            return array_merge($base, $items);
        }

        foreach ($items as $key => $value) {
            if (is_int($key)) {
                $base[] = $value;

                continue;
            }

            if (!array_key_exists($key, $base) || !is_array($base[$key]) || !is_array($value)) {
                $base[$key] = $value;

                continue;
            }

            $base[$key] = array_is_list($base[$key]) || array_is_list($value)
                ? array_merge($base[$key], $value)
                : self::merge($base[$key], $value, self::MERGE_DEEP);
        }

        return $base;
    }

    private static function assertValidStrategy(string $strategy): void
    {
        if (!in_array($strategy, [self::MERGE_SHALLOW, self::MERGE_DEEP], true)) {
            throw new ContainerException('Unknown add() merge strategy: ' . $strategy);
        }
    }
}
