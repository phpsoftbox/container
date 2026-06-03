<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD | Attribute::TARGET_PARAMETER)]
final readonly class Inject
{
    /**
     * @param string|array<string|int, string>|null $name
     */
    public function __construct(
        public string|array|null $name = null,
    ) {
    }
}
