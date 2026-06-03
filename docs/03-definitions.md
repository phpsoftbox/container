# Definitions DSL

Контейнер поддерживает определения как raw-значения, callable и helper-DSL.

## Хелперы

- `autowire(?string $class = null)`
- `create(?string $class = null)`
- `factory(mixed $factory)`
- `get(string $id)` (alias)
- `value(mixed $value)`
- `decorate(mixed $decorator)`
- `add(array $items, string $strategy = AddDefinition::MERGE_SHALLOW)` (расширение массивов)
- `env(string $name, mixed $default = null)`
- `string(string $value)` (`{entry}` interpolation)
- `lazy(?string $id = null, ?string $class = null)`

## Примеры

```php
use PhpSoftBox\Container\Definition\AddDefinition;
use function PhpSoftBox\Container\autowire;
use function PhpSoftBox\Container\add;
use function PhpSoftBox\Container\create;
use function PhpSoftBox\Container\decorate;
use function PhpSoftBox\Container\env;
use function PhpSoftBox\Container\factory;
use function PhpSoftBox\Container\get;
use function PhpSoftBox\Container\lazy;
use function PhpSoftBox\Container\string;
use function PhpSoftBox\Container\value;

return [
    'app.name' => 'phpsoftbox',
    'app.env' => env('APP_ENV', 'dev'),
    'base.path' => '/srv/app',
    'log.path' => string('{base.path}/var/log/app.log'),
    'config' => add(['feature.enabled' => true]),
    'nested.config' => add(
        ['db' => ['hosts' => ['replica']]],
        AddDefinition::MERGE_DEEP,
    ),
    LoggerInterface::class => autowire(Logger::class),
    Mailer::class => create(SmtpMailer::class)
        ->constructorParameter('dsn', 'smtp://localhost')
        ->property('logger', get(LoggerInterface::class))
        ->method('setEnvironment', get('app.env')),
    UserService::class => factory([UserServiceFactory::class, 'create']),
    UserServiceInterface::class => get(UserService::class),
    'clock' => value(fn () => 'raw closure'),
    CachedUserService::class => decorate(
        static fn (UserServiceInterface $previous): UserServiceInterface => new CachedUserService($previous),
    ),
    HeavyServiceInterface::class => lazy(HeavyService::class, HeavyService::class),
];
```

## Runtime `set()`

`set($id, $value)` принимает те же виды определений:

- raw scalar/object => value definition;
- callable => factory definition;
- `decorate(...)` => добавляет decorator;
- `add(...)` => расширяет массив entry;
- `lazy(...)` => добавляет lazy entry.

Важно: closure в `set()` трактуется как factory.  
Если closure должна храниться как значение, оборачивайте в `value(...)`.

## Wildcard definitions

Можно задавать шаблоны через `*`:

```php
return [
    'App\\Domain\\*RepositoryInterface' => create('App\\Infrastructure\\*DoctrineRepository'),
];
```

При резолве контейнер подставит wildcard в target class/alias/string definition.
Если несколько шаблонов подходят, выбирается более специфичный: меньше `*`, затем длиннее literal-часть, затем порядок регистрации.

## String interpolation

`string('{entry.id}')` подставляет значение entry. Literal braces можно экранировать двойными скобками:

```php
return [
    'app.name' => 'PhpSoftBox',
    'line' => string('{{app.name}} {app.name|lower}'),
];
```

Поддержанные transform-ы: `trim`, `upper`, `lower`, `urlencode`, `json`.

## Порядок применения decorators

Decorators применяются в порядке регистрации:

1. базовое определение;
2. первый decorator;
3. второй decorator;
4. и т.д.
