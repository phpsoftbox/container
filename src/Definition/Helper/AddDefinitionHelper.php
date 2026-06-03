<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Definition\Helper;

use PhpSoftBox\Container\Definition\AddDefinition;
use PhpSoftBox\Container\Definition\DefinitionInterface;

final readonly class AddDefinitionHelper implements DefinitionHelperInterface
{
    /**
     * @param array<int|string, mixed> $items
     */
    public function __construct(
        private array $items,
        private string $strategy = AddDefinition::MERGE_SHALLOW,
    ) {
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

    public function deep(): self
    {
        return new self($this->items, AddDefinition::MERGE_DEEP);
    }

    public function toDefinition(string $id): DefinitionInterface
    {
        return new AddDefinition($this->items, $this->strategy);
    }
}
