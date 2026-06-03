# Quick Start

```php
<?php

use PhpSoftBox\Container\ContainerBuilder;
use Psr\Container\ContainerInterface;
use function PhpSoftBox\Container\autowire;
use function PhpSoftBox\Container\factory;
use function PhpSoftBox\Container\get;

$builder = new ContainerBuilder();
$builder->useAutowiring(true);
$builder->useAttributes(false);

$builder->addDefinitions([
    LoggerInterface::class => autowire(Logger::class),
    UserRepository::class => autowire(UserRepository::class),
    UserService::class => factory(
        static fn (ContainerInterface $c): UserService => new UserService(
            $c->get(UserRepository::class),
            $c->get(LoggerInterface::class),
        ),
    ),
    UserServiceInterface::class => get(UserService::class),
]);

$container = $builder->build();
```

## Определения из файла

```php
$builder->addDefinitions(__DIR__ . '/config/container.php');
```

Файл должен возвращать массив.

## Production режим

```php
$builder->enableCompilation(__DIR__ . '/var/cache/di');
$container = $builder->build();
```

## Warmup

```php
// Полный прогрев: resolve entry + initialize lazy proxies
$container = $builder->warmup();

// Только проверка wiring (без инициализации lazy)
$container = $builder->warmup([], false);
```
