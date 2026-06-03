# Runtime API

## `get(string $id): mixed`

Возвращает shared-значение entry (кешируется после первого resolve).

## `has(string $id): bool`

`true`, если:

- entry явно зарегистрирован;
- есть wildcard definition, подходящий под id;
- или это автоваеримый instantiable class (при включенном autowiring).
- или entry доступен во внешнем `wrappedContainer`.

## `set(string $id, mixed $value): void`

Регистрирует/переопределяет определение в runtime.

## `make(string $id, array $parameters = []): mixed`

Создает fresh значение для конкретного entry (без reuse top-level результата).  
Зависимости внутри графа остаются shared, если они резолвятся через `get()`.

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

## `call(mixed $callable, array $parameters = []): mixed`

Вызов callable с поддержкой:

- named и positional параметров;
- DI по type-hint;
- inline definition helpers в аргументах;
- callable-форм вида `['ClassName', 'method']`:
  - static метод вызывается статически;
  - не-static метод вызывается на instance из контейнера.

## `injectOn(object $instance): void`

Применяет injection к уже созданному объекту:

- DSL method/property injections (если есть `ObjectDefinition` для класса);
- attribute injection (если `useAttributes(true)`).

## Сервисные интерфейсы

- `FactoryInterface` резолвится в контейнер (доступ к `make()`);
- `InvokerInterface` резолвится в контейнер (доступ к `call()`).

## Diagnostics

`diagnostics()` возвращает `ContainerDiagnostics`.

- `diagnostics()->canResolve(string $id): bool` — проверяет, сможет ли контейнер найти entry.
- `diagnostics()->why(string $id): array` — возвращает source резолва (`definition`, `wildcard`, `autowire`, `wrapped`, `not-found`) и базовые metadata.
- `diagnostics()->trace(string $id): array` — показывает alias-chain для entry.
- `diagnostics()->graph(string $id): array` — строит reflection-граф зависимостей без создания объектов: constructor, method/property injection, `#[Inject]` при включенных attributes и string interpolation.
- `diagnostics()->renderGraph(string $id): string` — CLI/UI-friendly text output для graph.
- `diagnostics()->aot(string $id): array` — объясняет, попадет ли entry в текущий AOT fast-path, с `kind` и списком `reasons`.
- `diagnostics()->aotPlan(): array` — возвращает precomputed AOT-план для всех explicit definitions.
- `diagnostics()->renderAotPlan(): string` — CLI/UI-friendly text output для AOT plan.
- `diagnostics()->validate(array $ids = []): array` — проверяет dependency graph без создания объектов и возвращает `valid`, `checked`, `issues`.
- `diagnostics()->renderValidation(array $ids = []): string` — CLI/UI-friendly text output для validation report.

Для совместимости контейнер также проксирует `canResolve()`, `why()`, `trace()`, `graph()`, `aot()`, `aotPlan()` и `validate()` напрямую.

`ContainerBuilder::validate(array $entries = []): array` собирает контейнер и запускает такую же проверку до `warmup()`.

## Ошибки

- `NotFoundException` — entry не найден.
- `ContainerException` — ошибки резолва/инъекций/типов/циклов.

## Cycle detection

При циклах контейнер возвращает читаемый путь, например:

`Circular dependency detected: a -> b -> a`.
