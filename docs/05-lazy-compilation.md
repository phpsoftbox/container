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

Если fingerprint совпадает, кеш переиспользуется. Fingerprint учитывает определения, файлы определений
и сигнатуры конструкторов классов, которые AOT вшивает в сгенерированный класс (параметры, типы,
значения по умолчанию, `#[Inject]`, существование и instantiable-состояние классов из типов). Поэтому
после `composer update`, изменившего конструктор, будет сгенерирован новый класс, а не подключён устаревший.
Для этого на каждом `build()` классы из `create()`/`autowire()`-определений загружаются для рефлексии.

Файлы кеша пишутся атомарно (временный файл в том же каталоге + `rename()`): параллельные воркеры
никогда не подключат недописанный класс.

Если AOT не может однозначно вычислить аргумент конструктора на этапе компиляции (union/intersection-тип,
wildcard-определение, обёрнутый контейнер, значение по умолчанию-объект), этот аргумент резолвится
в runtime той же логикой, что и без компиляции, — результат AOT и runtime совпадает.

AOT покрывает exportable values, string definitions, factory definitions, object definitions без lazy и decorated entries, если base definition уже AOT-compatible. Attribute injection поддерживается для constructor/property/method injection: constructor `#[Inject]` компилируется в `get(...)`, а property/method attributes применяются через обычный `injectObject()`. Decorator chain и factory calls используют те же runtime-механизмы, чтобы не менять семантику callable-аргументов.

Проверить причину и общий план можно через diagnostics:

```php
$container->diagnostics()->aot(App\Service::class);
$container->diagnostics()->aotPlan();
```

Cache можно сбросить явно через настроенный builder:

```php
$builder->invalidateCompilationCache();
```

или только по каталогу, без сборки определений (для CLI-команды сброса кеша и деплоя):

```php
use PhpSoftBox\Container\ContainerBuilder;

$removed = ContainerBuilder::clearCompiledCache(__DIR__ . '/var/cache/di'); // количество удалённых файлов
```

`clearCompiledCache()` удаляет `container.compiled.php`, все `CompiledContainer_*.php` (включая классы
старых fingerprint-ов) и брошенные временные файлы; отсутствующий каталог ошибкой не считается.

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
2. сбросить старый кеш: `ContainerBuilder::clearCompiledCache($dir)`;
3. выполнить `$builder->validate();`;
4. выполнить `$builder->warmup();`;
5. отдавать уже прогретый кеш в production окружение.
