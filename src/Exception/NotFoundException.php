<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Exception;

use Psr\Container\NotFoundExceptionInterface;

final class NotFoundException extends ContainerException implements NotFoundExceptionInterface
{
}
