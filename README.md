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

- PHP `^8.5` (lazy-объекты используют API Reflection PHP 8.5)
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

Файлы кеша пишутся атомарно (tmp + `rename()`), fingerprint учитывает сигнатуры конструкторов, вшитые
в AOT-класс, поэтому после `composer update` устаревший класс не подключается.

Сброс кеша компиляции по каталогу (без сборки определений) — для CLI-команды и шага деплоя:

```php
ContainerBuilder::clearCompiledCache(__DIR__ . '/var/cache/di'); // int — количество удалённых файлов
```

## Интерполяция строк

`{entry.id}` подставляется только в явном `string('...')`. Обычные строки в определениях и строковые
аргументы `call()`/`make()` (например, параметры маршрута из URL) передаются как есть — пользовательский
ввод не может прочитать значения контейнера.

## Переменные окружения

`env('NAME', $default)` сначала читает `EnvStorage` из `phpsoftbox/env` (значения из `.env`), затем
`$_ENV`, `$_SERVER` и `getenv()`. Пакет `phpsoftbox/env` опционален: без него используются только
глобальные источники.

## Сброс состояния в воркерах

В долгоживущем процессе (воркер очереди, HTTP-воркер) сервисы-синглтоны переживают задачу: кеши, identity map ORM,
очереди cookie переходят в следующую. Общий хук — `ServicesResetter`: вызывайте `reset()` после каждой задачи.

```php
use PhpSoftBox\Container\Reset\ServicesResetter;

$resetter = new ServicesResetter($container, [
    // Классы из пакетов, которые не зависят от контейнера: запись → метод (или список методов).
    ConnectionManagerInterface::class => 'clearWarmup',
    EntityManagerInterface::class     => 'clear',
]);

$resetter->reset();
```

- Сбрасываются только уже созданные сервисы: реализующие `PhpSoftBox\Container\Reset\ResetInterface` и
  перечисленные в карте. Ради сброса ничего не создаётся (`Container::resolvedInstances()`/`resolvedInstance()`).
- Ошибка одного сброса не прерывает остальные; первое исключение бросается после обхода.
- Несуществующий метод в карте — `LogicException`: ошибка конфигурации видна сразу.

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
