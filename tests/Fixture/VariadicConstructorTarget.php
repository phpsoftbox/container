<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Tests\Fixture;

final class VariadicConstructorTarget
{
    /**
     * @param list<string> $items
     */
    public array $items;

    public function __construct(
        public readonly string $prefix,
        string ...$items,
    ) {
        $this->items = $items;
    }
}
