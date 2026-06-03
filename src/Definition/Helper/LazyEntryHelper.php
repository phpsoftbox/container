<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Definition\Helper;

final readonly class LazyEntryHelper
{
    public function __construct(
        private ?string $targetId = null,
        private ?string $className = null,
    ) {
    }

    public function targetId(string $entryId): string
    {
        return $this->targetId ?? $entryId;
    }

    public function className(): ?string
    {
        return $this->className;
    }
}
