<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Exception;

use Psr\Container\ContainerExceptionInterface;
use RuntimeException;

class ContainerException extends RuntimeException implements ContainerExceptionInterface
{
}
