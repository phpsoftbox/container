# About

`phpsoftbox/container` — контейнер зависимостей с фокусом на:

- простую конфигурацию через PHP-массивы/хелперы;
- предсказуемый runtime API;
- совместимость с PSR-11;
- lazy через нативные возможности PHP 8.5.

## Поддерживаемые сценарии

- singleton-like сервисы через `get()`;
- factory/alias/value/object definitions;
- autowiring constructor-параметров;
- method/property injection через DSL `create()/autowire()`;
- attribute injection через `#[Inject]` и `#[Injectable]`;
- `injectOn($instance)` для уже созданных объектов;
- runtime override через `set()`;
- decorate-цепочки;
- `call()` с автоподстановкой class-типов;
- wildcard definitions (`*`) для шаблонных биндингов;
- env/string/add helpers;
- union/intersection type resolution в `call()` и `instantiate()`.

## Ограничения текущей версии

- `add()` по умолчанию использует поверхностное слияние, deep merge включается явно;
- string interpolation поддерживает формат `{entry.id}`, escape через `{{entry.id}}` и базовые transform-ы;
- wildcard pattern работает по простому `*`-матчингу по FQCN-сегментам с приоритетом более специфичного шаблона;
- compilation покрывает safe AOT fast-path, но неподдержанные dynamic cases остаются на обычном runtime resolve.

План следующих улучшений: [06-php-di-gap.md](06-php-di-gap.md).
