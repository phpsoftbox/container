# Full Examples

Ниже пример конфигурации, которую можно положить в `config/container.php`, и production bootstrap с validation, diagnostics output, compilation и warmup.

## `config/container.php`

```php
<?php

declare(strict_types=1);

use App\Auth\AuthMiddleware;
use App\Auth\TokenParser;
use App\Config\AppConfig;
use App\Http\Controller\UserController;
use App\Logging\Logger;
use App\Logging\LoggerInterface;
use App\Mail\Mailer;
use App\Mail\SmtpMailer;
use App\Repository\UserDoctrineRepository;
use App\Repository\UserRepositoryInterface;
use App\Service\HeavyReportService;
use App\Service\HeavyReportServiceInterface;
use App\Service\UserService;
use App\Service\UserServiceFactory;
use App\Service\UserServiceInterface;
use Psr\Container\ContainerInterface;
use function PhpSoftBox\Container\autowire;
use function PhpSoftBox\Container\create;
use function PhpSoftBox\Container\env;
use function PhpSoftBox\Container\factory;
use function PhpSoftBox\Container\get;
use function PhpSoftBox\Container\lazy;
use function PhpSoftBox\Container\string;
use function PhpSoftBox\Container\value;

return [
    'app.env' => env('APP_ENV', 'prod'),
    'app.debug' => env('APP_DEBUG', false),
    'app.root' => dirname(__DIR__),
    'app.name' => 'Example App',

    AppConfig::class => create(AppConfig::class)
        ->constructorParameter('name', get('app.name'))
        ->constructorParameter('env', get('app.env'))
        ->constructorParameter('debug', get('app.debug')),

    'path.var' => string('{app.root}/var'),
    'path.cache' => string('{app.root}/var/cache'),
    'path.logs' => string('{app.root}/var/log'),

    LoggerInterface::class => autowire(Logger::class)
        ->constructorParameter('path', string('{app.root}/var/log/app.log')),

    Mailer::class => create(SmtpMailer::class)
        ->constructorParameter('dsn', env('MAILER_DSN', 'smtp://localhost'))
        ->property('logger', get(LoggerInterface::class)),

    UserRepositoryInterface::class => create(UserDoctrineRepository::class)
        ->constructorParameter('dsn', env('DATABASE_DSN')),

    UserService::class => factory([UserServiceFactory::class, 'create']),
    UserServiceInterface::class => get(UserService::class),

    HeavyReportService::class => autowire(HeavyReportService::class),
    HeavyReportServiceInterface::class => lazy(HeavyReportService::class, HeavyReportService::class),

    AuthMiddleware::class => autowire(AuthMiddleware::class)
        ->constructorParameter('parser', get(TokenParser::class)),

    UserController::class => autowire(UserController::class),

    'clock' => value(static fn (): int => time()),
];
```

Если один и тот же key нужно расширять через `add()` или `decorate()`, регистрируйте definitions несколькими вызовами `addDefinitions()`. В одном PHP-массиве одинаковые ключи будут перезаписаны PHP.

## Builder Flow

```php
<?php

declare(strict_types=1);

use PhpSoftBox\Container\ContainerBuilder;
use PhpSoftBox\Container\Definition\AddDefinition;
use App\Service\CachedUserService;
use App\Service\UserServiceInterface;
use function PhpSoftBox\Container\add;
use function PhpSoftBox\Container\decorate;

$builder = new ContainerBuilder();

$builder
    ->useAutowiring(true)
    ->useAttributes(true)
    ->enableCompilation(__DIR__ . '/../var/cache/container')
    ->addDefinitions(__DIR__ . '/../config/container.php');

$builder->addDefinitions([
    'features' => ['users' => true],
]);
$builder->addDefinitions([
    'features' => add(['reports' => true], AddDefinition::MERGE_DEEP),
    UserServiceInterface::class => decorate(
        static fn (UserServiceInterface $previous): UserServiceInterface => new CachedUserService($previous),
    ),
]);

$container = $builder->build();
$validation = $container->validate();
if (!$validation['valid']) {
    fwrite(STDERR, $container->diagnostics()->renderValidation());
    exit(1);
}

$container = $builder->warmup([], initializeLazy: false);

echo $container->diagnostics()->renderAotPlan();
```

## Diagnostics Output

```php
$diagnostics = $container->diagnostics();

echo $diagnostics->renderGraph(App\Service\UserService::class);
echo "\n\n";
echo $diagnostics->renderValidation();
echo "\n\n";
echo $diagnostics->renderAotPlan();
```

Для машинной обработки используйте массивы:

```php
$diagnostics->graph(App\Service\UserService::class);
$diagnostics->validate();
$diagnostics->aotPlan();
```

## CLI Diagnostics

Компонент регистрирует provider так же, как `Database`: через `extra.psb.providers` в `composer.json`.

```bash
php vendor/bin/psb container:validate
php vendor/bin/psb container:validate --entry App\Service\UserService --entry App\Http\Controller\UserController
php vendor/bin/psb container:graph App\Service\UserService
php vendor/bin/psb container:aot
php vendor/bin/psb container:aot App\Service\UserService
```
