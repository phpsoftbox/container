# Advanced Features + Roadmap

Актуальный статус: 2026-06-03.

## Что уже реализовано

- PSR-11 API: `get()` / `has()`
- runtime API: `set()` / `make()` / `call()` / `injectOn()`
- DSL helpers: `autowire`, `create`, `factory`, `get`, `value`, `decorate`, `lazy`, `add`, `env`, `string`
- method/property injection через `create()/autowire()`
- attribute injection: `#[Inject]` и `#[Injectable(lazy: true)]`
- wildcard definitions
- lazy proxies на `ReflectionClass::newLazyProxy()`
- compilation cache (`container.compiled.php`) + generated compiled class file + limited AOT fast-path
- optional definition cache (`DefinitionCacheInterface`, `ApcuDefinitionCache`)
- explicit compilation cache invalidation (`invalidateCompilationCache()`)
- container wrapping (`wrapContainer()`)
- биндинг `FactoryInterface` и `InvokerInterface` на контейнер
- `add()` с явной стратегией `MERGE_SHALLOW` / `MERGE_DEEP`
- string interpolation с escape и transform-ами (`trim`, `upper`, `lower`, `urlencode`, `json`)
- wildcard definitions с приоритетом более специфичного шаблона
- diagnostics API: `ContainerDiagnostics`, `canResolve()`, `why()`, `trace()`, `graph()`, `aot()`
- diagnostics text output: `renderGraph()`, `renderValidation()`, `renderAotPlan()`
- CLI integration через `ContainerCommandProvider`: `container:validate`, `container:graph`, `container:aot`
- precomputed AOT plan: `aotPlan()`
- build-time dependency graph validation: `validate()`
- full examples: definitions file, builder flow, diagnostics output

## Технические ограничения и направления улучшений

| Тема | Текущее поведение | Куда улучшать |
| --- | --- | --- |
| Compilation | AOT покрывает exportable values, string/factory/object definitions, decorated entries с AOT-compatible base, attribute-aware object construction и precomputed plan; `aot()` объясняет причины skip | Дальше расширять только конкретные runtime-safe случаи, если появятся |
| Diagnostics | `why()/trace()` объясняют source и alias-chain; `graph()` строит dependency graph; `validate()` ловит циклы, missing entries, required scalar parameters и reflection issues; render-методы подключены к CLI-командам | Улучшать представление graph/report по обратной связи из реальных backend-приложений |

## Следующие направления

Текущие roadmap-пункты по factories/attributes/precompute, diagnostics output и full examples закрыты. Следующие работы лучше вести как hardening:

1. Benchmark AOT/runtime/warmup на реальном приложении.
2. Дополнить примеры кейсами из первого реального backend-приложения.
3. Добавить CLI-команды для warmup/compilation cache, когда в приложении появится явный `ContainerBuilder` lifecycle для deploy/CI.
