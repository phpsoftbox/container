<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Tests\Fixture;

final class UserDoctrineRepository implements UserRepositoryInterface
{
    public function source(): string
    {
        return 'doctrine';
    }
}
