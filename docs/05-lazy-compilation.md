# Lazy + Compilation

## Lazy

Lazy-entry можно объявить:

- через builder: `$builder->lazy($id, $className)`
- через definitions: `$id => lazy($targetId, $className)`
- runtime: `$container->set($id, lazy(...))`
- неявно через `#[Injectable(lazy: true)]` при включенных attributes.

Контейнер создает proxy через `ReflectionClass::newLazyProxy()`.

### Валидация

На `build()` выполняется build-time валидация lazy entries:

- должен быть выведен concrete class;
- class должен существовать;
- class не должен быть internal;
- class должен быть instantiable.

Если проверка не проходит — бросается `ContainerException` до runtime.

## Compilation cache

`enableCompilation($dir)` включает файловый кеш `container.compiled.php`:

- lazy map (`entry => class`);
- fingerprint конфигурации container builder;
- metadata по lazy-классам.
- generated class-файл `CompiledContainer_<...>.php`.
- AOT fast-path для поддержанных definitions.

Если fingerprint совпадает, кеш переиспользуется.

AOT покрывает exportable values, string definitions, factory definitions, object definitions без lazy и decorated entries, если base definition уже AOT-compatible. Attribute injection поддерживается для constructor/property/method injection: constructor `#[Inject]` компилируется в `get(...)`, а property/method attributes применяются через обычный `injectObject()`. Decorator chain и factory calls используют те же runtime-механизмы, чтобы не менять семантику callable-аргументов.

Проверить причину и общий план можно через diagnostics:

```php
$container->diagnostics()->aot(App\Service::class);
$container->diagnostics()->aotPlan();
```

Cache можно сбросить явно:

```php
$builder->invalidateCompilationCache();
```

## Definition cache

Builder поддерживает дополнительный in-memory cache слой:

```php
use PhpSoftBox\Container\Compilation\ApcuDefinitionCache;

$builder->setDefinitionCache(new ApcuDefinitionCache('container:'));
```

Этот слой ускоряет повторные `build()` в одном окружении поверх файлового compilation cache.

## Warmup

`warmup(array $entries = [], bool $initializeLazy = true): Container`

- по умолчанию резолвит все зарегистрированные entry;
- если `initializeLazy=true`, дополнительно материализует lazy proxies;
- если `false`, выполняет только resolve/wiring.

Практика для CI/deploy:

1. включить `enableCompilation(...)`;
2. выполнить `$builder->validate();`;
3. выполнить `$builder->warmup();`;
4. отдавать уже прогретый кеш в production окружение.
