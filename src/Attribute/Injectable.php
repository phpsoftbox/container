<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Injectable
{
    public function __construct(
        public bool $lazy = false,
    ) {
    }
}
