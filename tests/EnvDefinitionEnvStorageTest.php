<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Tests;

use PhpSoftBox\Container\Container;
use PhpSoftBox\Container\Definition\EnvDefinition;
use PhpSoftBox\Env\EnvStorage;
use PhpSoftBox\Env\Variables;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function getenv;
use function PhpSoftBox\Container\env;
use function putenv;

#[CoversClass(EnvDefinition::class)]
#[CoversMethod(EnvDefinition::class, 'resolve')]
final class EnvDefinitionEnvStorageTest extends TestCase
{
    private const string NAME = 'PSB_CONTAINER_DOTENV_ONLY';

    protected function tearDown(): void
    {
        EnvStorage::clear();
        unset($_ENV[self::NAME], $_SERVER[self::NAME]);
        putenv(self::NAME);
    }

    /**
     * Проверим, что env() видит значение, загруженное phpsoftbox/env из .env в EnvStorage,
     * даже если оно не экспортировано в $_ENV/$_SERVER/getenv().
     *
     * @see EnvDefinition::resolve()
     */
    #[Test]
    public function resolveReadsValueFromEnvStorage(): void
    {
        EnvStorage::set(Variables::fromArray([self::NAME => 'from-dotenv']));

        // Убедимся, что в глобальных источниках значения нет.
        $this->assertArrayNotHasKey(self::NAME, $_ENV);
        $this->assertFalse(getenv(self::NAME));

        $container = new Container(['value' => env(self::NAME, 'default')]);

        $this->assertSame('from-dotenv', $container->get('value'));
    }

    /**
     * Проверим, что при отсутствии переменной в EnvStorage и глобальных источниках используется default.
     *
     * @see EnvDefinition::resolve()
     */
    #[Test]
    public function resolveFallsBackToDefaultWhenVariableIsMissing(): void
    {
        EnvStorage::set(Variables::fromArray([]));

        $container = new Container(['value' => env(self::NAME, 'default')]);

        $this->assertSame('default', $container->get('value'));
    }
}
