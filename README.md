# PhpSoftBox Container

## About

`phpsoftbox/container` — DI-контейнер для PhpSoftBox с PSR-11 API и дополнительными runtime-операциями.

Ключевые возможности:

- `get()` / `has()` (PSR-11)
- runtime `set()` определений
- `make()` для не-shared резолва entry
- `call()` для вызова callable с DI параметров
- autowiring по type-hint
- decorators (`decorate`)
- native lazy-прокси на PHP 8.5 (`ReflectionClass::newLazyProxy`)
- build-time валидация lazy-конфигурации
- compilation metadata cache (`enableCompilation()`)
- warmup (`warmup()`)

## Требования

- PHP `^8.4` (фактически lazy использует API PHP 8.5 Reflection)
- `psr/container:^2.0`

## Quick Start

```php
<?php

use PhpSoftBox\Container\ContainerBuilder;
use Psr\Container\ContainerInterface;
use function PhpSoftBox\Container\autowire;
use function PhpSoftBox\Container\factory;
use function PhpSoftBox\Container\get;

$builder = new ContainerBuilder();
$builder->useAutowiring(true);
$builder->addDefinitions([
    LoggerInterface::class => autowire(Logger::class),
    UserService::class => factory(
        static fn (ContainerInterface $c): UserService => new UserService($c->get(LoggerInterface::class)),
    ),
    UserServiceInterface::class => get(UserService::class),
]);
$builder->lazy(UserService::class);

$container = $builder->build();
```

## Production

```php
$builder->enableCompilation(__DIR__ . '/var/cache/di');
$container = $builder->build();
```

`enableCompilation()` записывает `container.compiled.php` с fingerprint конфигурации и lazy metadata.

Для CI/deploy можно прогреть контейнер:

```php
$container = $builder->warmup();
```

`warmup([], false)` прогревает wiring без инициализации lazy-объектов.

## PhpStorm meta

Чтобы PhpStorm выводил тип сервиса по `SomeService::class`, можно добавить в корень
проекта `.phpstorm.meta.php`:

```php
<?php

namespace PHPSTORM_META
{
    override(\Psr\Container\ContainerInterface::get(0), map([
        '' => '@',
    ]));

    override(\PhpSoftBox\Container\Container::get(0), map([
        '' => '@',
    ]));

    override(\PhpSoftBox\Container\Container::make(0), map([
        '' => '@',
    ]));

    override(\PhpSoftBox\Container\FactoryInterface::make(0), map([
        '' => '@',
    ]));
}
```

После этого IDE должна понимать:

```php
$logger = $container->get(LoggerInterface::class); // LoggerInterface
$service = $container->make(UserService::class); // UserService
```

## Оглавление

- [Документация](docs/index.md)
- [1. About](docs/01-about.md)
- [2. Quick Start](docs/02-quick-start.md)
- [3. Definitions DSL](docs/03-definitions.md)
- [4. Runtime API](docs/04-runtime-api.md)
- [5. Lazy + Compilation](docs/05-lazy-compilation.md)
- [6. PHP-DI parity gap](docs/06-php-di-gap.md)
- [7. Full Examples](docs/07-examples.md)
