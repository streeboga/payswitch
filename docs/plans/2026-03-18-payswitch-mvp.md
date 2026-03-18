# Payswitch MVP — План имплементации

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Цель:** Построить Hyperswitch-совместимый платёжный оркестратор на Laravel 13 с пакетной архитектурой для переиспользования в SDK.

**Архитектура:** Монорепо с Composer-пакетами в `packages/streeboga/`. Два пакета: `payment-data` (контракты + модели + миграции) и `payment-connectors` (OmniPay abstraction + PSP drivers). Laravel-приложение в `app/` — тонкий слой: controllers, middleware, routes, jobs, listeners.

**Стек:** Laravel 13, PHP 8.3+, PostgreSQL, Pest PHP, OmniPay, omnipay/stripe, Scramble (OpenAPI).

**Структура пакетов:**

```
packages/streeboga/
├── payment-data/               # Контракты, Enums, DTO, Models, Migrations, StateMachine
│   ├── composer.json
│   ├── src/
│   │   ├── Contracts/          # ConnectorInterface, RoutingInterface
│   │   ├── Enums/              # PaymentStatus, RefundStatus, CaptureMethod...
│   │   ├── Models/             # Все Eloquent models
│   │   ├── Repositories/       # Data access layer
│   │   ├── Support/            # IdGenerator, WebhookSigner
│   │   ├── StateMachine/       # PaymentStateMachine
│   │   ├── Exceptions/         # PaymentException, InvalidStateTransition...
│   │   └── PaymentDataServiceProvider.php
│   ├── database/migrations/
│   └── config/payswitch.php
│
└── payment-connectors/         # OmniPay abstraction + PSP drivers
    ├── composer.json
    ├── src/
    │   ├── AbstractConnector.php
    │   ├── ConnectorFactory.php
    │   ├── ConnectorErrorNormalizer.php
    │   ├── Drivers/StripeConnector.php
    │   ├── Drivers/YooKassaConnector.php
    │   ├── Webhooks/IncomingWebhookHandler.php
    │   └── PaymentConnectorsServiceProvider.php
    └── tests/

app/                            # Laravel-specific (НЕ переиспользуется в SDK)
├── Actions/Payswitch/          # Бизнес-логика операций
├── Http/Controllers/Api/       # Тонкие контроллеры
├── Http/Middleware/             # API key auth, rate limiting
├── Http/Requests/Api/          # FormRequests
├── Events/                     # PaymentStatusChanged
├── Listeners/                  # LogPaymentAudit, SendWebhookNotification
├── Jobs/                       # DeliverWebhookJob
├── Services/                   # RoutingService, WebhookService
└── Console/Commands/           # PayswitchSeedCommand
```

---

## Задача 1: Scaffold пакетов и конфигурация проекта

**Файлы:**
- Create: `packages/streeboga/payment-data/composer.json`
- Create: `packages/streeboga/payment-data/src/PaymentDataServiceProvider.php`
- Create: `packages/streeboga/payment-data/config/payswitch.php`
- Create: `packages/streeboga/payment-connectors/composer.json`
- Create: `packages/streeboga/payment-connectors/src/PaymentConnectorsServiceProvider.php`
- Modify: `composer.json` (root) — repositories + require
- Modify: `.env`, `.env.example` — PostgreSQL + admin key
- Modify: `bootstrap/app.php` — API routing
- Create: `routes/api.php`

**Шаг 1: Создать структуру каталогов**

```bash
mkdir -p packages/streeboga/payment-data/{src/{Contracts,Enums,Models,Repositories,Support,StateMachine,Exceptions},database/migrations,config}
mkdir -p packages/streeboga/payment-connectors/{src/{Drivers,Webhooks},tests}
```

**Шаг 2: composer.json для payment-data**

```json
{
    "name": "streeboga/payment-data",
    "description": "Payment data layer: contracts, enums, models, migrations for Payswitch",
    "type": "library",
    "license": "proprietary",
    "autoload": {
        "psr-4": {
            "Streeboga\\PaymentData\\": "src/"
        }
    },
    "extra": {
        "laravel": {
            "providers": [
                "Streeboga\\PaymentData\\PaymentDataServiceProvider"
            ]
        }
    },
    "require": {
        "php": "^8.3",
        "illuminate/database": "^13.0",
        "illuminate/support": "^13.0"
    }
}
```

**Шаг 3: PaymentDataServiceProvider**

```php
<?php
// packages/streeboga/payment-data/src/PaymentDataServiceProvider.php

namespace Streeboga\PaymentData;

use Illuminate\Support\ServiceProvider;

class PaymentDataServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/payswitch.php', 'payswitch');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/payswitch.php' => config_path('payswitch.php'),
            ], 'payswitch-config');

            $this->publishes([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'payswitch-migrations');
        }
    }
}
```

**Шаг 4: config/payswitch.php**

```php
<?php
// packages/streeboga/payment-data/config/payswitch.php

return [
    'admin_api_key' => env('PAYSWITCH_ADMIN_API_KEY', ''),
    'environment' => env('PAYSWITCH_ENVIRONMENT', 'sandbox'),

    'id_prefixes' => [
        'payment' => 'pay_',
        'customer' => 'cus_',
        'refund' => 'ref_',
        'profile' => 'pro_',
        'merchant' => 'merchant_',
        'mca' => 'mca_',
        'org' => 'org_',
        'api_key_sandbox' => 'snd_',
        'api_key_production' => 'prod_',
        'publishable_key' => 'pk_',
        'event' => 'evt_',
    ],

    'payment' => [
        'session_expiry' => 900,
        'id_length' => 26,
    ],

    'webhook' => [
        'retry_schedule' => [1, 5, 5, 10, 10, 10, 10, 10, 60, 60, 60, 60, 60, 360, 360, 360],
        'max_attempts' => 16,
        'timeout' => 30,
    ],

    'rate_limit' => [
        'api' => 60,            // запросов в минуту для Secret API Key
        'admin' => 30,          // запросов в минуту для Admin API Key
        'publishable' => 120,   // запросов в минуту для Publishable Key
    ],
];
```

**Шаг 5: composer.json для payment-connectors**

```json
{
    "name": "streeboga/payment-connectors",
    "description": "OmniPay connector abstraction layer for Payswitch",
    "type": "library",
    "license": "proprietary",
    "autoload": {
        "psr-4": {
            "Streeboga\\PaymentConnectors\\": "src/"
        }
    },
    "extra": {
        "laravel": {
            "providers": [
                "Streeboga\\PaymentConnectors\\PaymentConnectorsServiceProvider"
            ]
        }
    },
    "require": {
        "php": "^8.3",
        "streeboga/payment-data": "*",
        "omnipay/common": "^3.0",
        "omnipay/stripe": "^3.0"
    }
}
```

**Шаг 6: PaymentConnectorsServiceProvider**

```php
<?php
// packages/streeboga/payment-connectors/src/PaymentConnectorsServiceProvider.php

namespace Streeboga\PaymentConnectors;

use Illuminate\Support\ServiceProvider;

class PaymentConnectorsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ConnectorFactory::class);
    }
}
```

**Шаг 7: Обновить root composer.json**

Добавить:
```json
{
    "repositories": [
        { "type": "path", "url": "packages/streeboga/payment-data", "options": {"symlink": true} },
        { "type": "path", "url": "packages/streeboga/payment-connectors", "options": {"symlink": true} }
    ],
    "require": {
        "streeboga/payment-data": "*",
        "streeboga/payment-connectors": "*",
        "dedoc/scramble": "^0.12"
    }
}
```

```bash
composer update
```

**Шаг 8: .env и .env.example**

Добавить:
```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=payswitch
DB_USERNAME=payswitch
DB_PASSWORD=secret

PAYSWITCH_ADMIN_API_KEY=admin_test_key_for_development
PAYSWITCH_ENVIRONMENT=sandbox
```

**Шаг 9: routes/api.php + bootstrap/app.php**

```php
<?php
// routes/api.php
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', function () {
        $dbOk = true;
        try {
            \Illuminate\Support\Facades\DB::connection()->getPdo();
        } catch (\Exception $e) {
            $dbOk = false;
        }

        $status = $dbOk ? 'healthy' : 'degraded';
        $code = $dbOk ? 200 : 503;

        return response()->json([
            'status' => $status,
            'database' => $dbOk ? 'connected' : 'disconnected',
            'timestamp' => now()->toIso8601String(),
        ], $code);
    });
});
```

В `bootstrap/app.php` добавить `api:` в `->withRouting()`:
```php
->withRouting(
    web: __DIR__.'/../routes/web.php',
    api: __DIR__.'/../routes/api.php',
    apiPrefix: 'api',
    commands: __DIR__.'/../routes/console.php',
    health: '/up',
)
```

**Шаг 10: Проверить**

```bash
composer update
./vendor/bin/pest
php artisan config:show payswitch
curl http://localhost:8000/api/v1/health
```

**Шаг 11: Коммит**

```bash
git add -A
git commit -m "feat: scaffold package architecture — payment-data, payment-connectors, health endpoint"
```

---

## Задача 2: payment-data — Enums

**Файлы (в `packages/streeboga/payment-data/src/Enums/`):**
- Create: `PaymentStatus.php`
- Create: `RefundStatus.php`
- Create: `CaptureMethod.php`
- Create: `AuthenticationType.php`
- Create: `ConnectorType.php`
- Create: `ApiKeyType.php`
- Create: `WebhookEventType.php`

**Шаг 1: Создать PaymentStatus**

```php
<?php
// packages/streeboga/payment-data/src/Enums/PaymentStatus.php

namespace Streeboga\PaymentData\Enums;

enum PaymentStatus: string
{
    case REQUIRES_PAYMENT_METHOD = 'requires_payment_method';
    case REQUIRES_CONFIRMATION = 'requires_confirmation';
    case REQUIRES_CUSTOMER_ACTION = 'requires_customer_action';
    case REQUIRES_MERCHANT_ACTION = 'requires_merchant_action';
    case PROCESSING = 'processing';
    case REQUIRES_CAPTURE = 'requires_capture';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';
    case PARTIALLY_CAPTURED = 'partially_captured';
    case PARTIALLY_CAPTURED_AND_CAPTURABLE = 'partially_captured_and_capturable';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::SUCCEEDED, self::FAILED, self::CANCELLED,
            self::EXPIRED, self::PARTIALLY_CAPTURED,
        ]);
    }
}
```

**Шаг 2: Остальные enum'ы**

```php
<?php
// RefundStatus.php
namespace Streeboga\PaymentData\Enums;

enum RefundStatus: string
{
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
    case PENDING = 'pending';
    case MANUAL_REVIEW = 'manual_review';
}
```

```php
<?php
// CaptureMethod.php
namespace Streeboga\PaymentData\Enums;

enum CaptureMethod: string
{
    case AUTOMATIC = 'automatic';
    case MANUAL = 'manual';
}
```

```php
<?php
// AuthenticationType.php
namespace Streeboga\PaymentData\Enums;

enum AuthenticationType: string
{
    case THREE_DS = 'three_ds';
    case NO_THREE_DS = 'no_three_ds';
}
```

```php
<?php
// ConnectorType.php
namespace Streeboga\PaymentData\Enums;

enum ConnectorType: string
{
    case FIZ_OPERATIONS = 'fiz_operations';
    case PAYOUT_PROCESSOR = 'payout_processor';
}
```

```php
<?php
// ApiKeyType.php
namespace Streeboga\PaymentData\Enums;

enum ApiKeyType: string
{
    case ADMIN = 'admin';
    case SECRET = 'secret';
    case PUBLISHABLE = 'publishable';
}
```

```php
<?php
// WebhookEventType.php
namespace Streeboga\PaymentData\Enums;

enum WebhookEventType: string
{
    case PAYMENT_SUCCEEDED = 'payment_succeeded';
    case PAYMENT_FAILED = 'payment_failed';
    case PAYMENT_PROCESSING = 'payment_processing';
    case PAYMENT_CANCELLED = 'payment_cancelled';
    case PAYMENT_AUTHORIZED = 'payment_authorized';
    case PAYMENT_CAPTURED = 'payment_captured';
    case ACTION_REQUIRED = 'action_required';
    case REFUND_SUCCEEDED = 'refund_succeeded';
    case REFUND_FAILED = 'refund_failed';
}
```

**Шаг 3: Коммит**

```bash
git add -A
git commit -m "feat(payment-data): add all domain enums"
```

---

## Задача 3: payment-data — Support утилиты и Exceptions

**Файлы (в `packages/streeboga/payment-data/src/`):**
- Create: `Support/IdGenerator.php`
- Create: `Support/WebhookSigner.php`
- Create: `Exceptions/PaymentException.php`
- Create: `Exceptions/ConnectorException.php`
- Create: `Exceptions/InvalidStateTransitionException.php`
- Create: `Exceptions/ApiAuthenticationException.php`
- Create: `Contracts/ConnectorInterface.php`
- Test: `tests/Unit/Support/IdGeneratorTest.php`
- Test: `tests/Unit/Support/WebhookSignerTest.php`

**Шаг 1: Тест IdGenerator**

```php
<?php
// tests/Unit/Support/IdGeneratorTest.php

use Streeboga\PaymentData\Support\IdGenerator;

test('payment id has pay_ prefix and 30 chars total', function () {
    $id = IdGenerator::paymentId();
    expect($id)->toStartWith('pay_')->toHaveLength(30);
});

test('each call generates unique id', function () {
    $ids = collect(range(1, 100))->map(fn () => IdGenerator::paymentId());
    expect($ids->unique()->count())->toBe(100);
});

test('client secret contains payment id and _secret_', function () {
    $pid = 'pay_abcdefghijklmnopqrstuvwxyz';
    expect(IdGenerator::clientSecret($pid))->toStartWith($pid . '_secret_');
});

test('sandbox api key starts with snd_', function () {
    expect(IdGenerator::apiKey('sandbox'))->toStartWith('snd_');
});

test('production api key starts with prod_', function () {
    expect(IdGenerator::apiKey('production'))->toStartWith('prod_');
});

test('publishable key starts with pk_snd_ for sandbox', function () {
    expect(IdGenerator::publishableKey('sandbox'))->toStartWith('pk_snd_');
});

test('merchant id starts with merchant_', function () {
    expect(IdGenerator::merchantId())->toStartWith('merchant_');
});

test('all id types generate with correct prefixes', function () {
    expect(IdGenerator::customerId())->toStartWith('cus_');
    expect(IdGenerator::refundId())->toStartWith('ref_');
    expect(IdGenerator::profileId())->toStartWith('pro_');
    expect(IdGenerator::mcaId())->toStartWith('mca_');
    expect(IdGenerator::orgId())->toStartWith('org_');
    expect(IdGenerator::eventId())->toStartWith('evt_');
});
```

**Шаг 2: Запустить — убедиться что падает**

```bash
./vendor/bin/pest tests/Unit/Support/IdGeneratorTest.php
```

**Шаг 3: Реализовать IdGenerator**

```php
<?php
// packages/streeboga/payment-data/src/Support/IdGenerator.php

namespace Streeboga\PaymentData\Support;

use Illuminate\Support\Str;

class IdGenerator
{
    public static function paymentId(): string
    {
        return self::generate(config('payswitch.id_prefixes.payment'), config('payswitch.payment.id_length'));
    }

    public static function customerId(): string { return self::generate(config('payswitch.id_prefixes.customer')); }
    public static function refundId(): string { return self::generate(config('payswitch.id_prefixes.refund')); }
    public static function profileId(): string { return self::generate(config('payswitch.id_prefixes.profile')); }
    public static function merchantId(): string { return self::generate(config('payswitch.id_prefixes.merchant'), 20); }
    public static function mcaId(): string { return self::generate(config('payswitch.id_prefixes.mca')); }
    public static function orgId(): string { return self::generate(config('payswitch.id_prefixes.org')); }
    public static function eventId(): string { return self::generate(config('payswitch.id_prefixes.event')); }

    public static function clientSecret(string $paymentId): string
    {
        return $paymentId . '_secret_' . Str::random(26);
    }

    public static function apiKey(string $environment): string
    {
        $prefix = $environment === 'production'
            ? config('payswitch.id_prefixes.api_key_production')
            : config('payswitch.id_prefixes.api_key_sandbox');
        return self::generate($prefix);
    }

    public static function publishableKey(string $environment): string
    {
        $envPrefix = $environment === 'production' ? 'prod_' : 'snd_';
        return config('payswitch.id_prefixes.publishable_key') . $envPrefix . Str::random(26);
    }

    private static function generate(string $prefix, int $randomLength = 26): string
    {
        return $prefix . Str::random($randomLength);
    }
}
```

**Шаг 4: Тест WebhookSigner**

```php
<?php
// tests/Unit/Support/WebhookSignerTest.php

use Streeboga\PaymentData\Support\WebhookSigner;

test('signs with HMAC-SHA512', function () {
    $sig = WebhookSigner::sign('payload', 'key');
    expect($sig)->toBe(hash_hmac('sha512', 'payload', 'key'));
});

test('verifies valid signature', function () {
    $sig = hash_hmac('sha512', 'data', 'secret');
    expect(WebhookSigner::verify('data', $sig, 'secret'))->toBeTrue();
});

test('rejects invalid signature', function () {
    expect(WebhookSigner::verify('data', 'wrong', 'secret'))->toBeFalse();
});
```

**Шаг 5: Реализовать WebhookSigner**

```php
<?php
// packages/streeboga/payment-data/src/Support/WebhookSigner.php

namespace Streeboga\PaymentData\Support;

class WebhookSigner
{
    public static function sign(string $payload, string $key): string
    {
        return hash_hmac('sha512', $payload, $key);
    }

    public static function verify(string $payload, string $signature, string $key): bool
    {
        return hash_equals(self::sign($payload, $key), $signature);
    }
}
```

**Шаг 6: Exceptions**

```php
<?php
// packages/streeboga/payment-data/src/Exceptions/ApiAuthenticationException.php
namespace Streeboga\PaymentData\Exceptions;

use Exception;

class ApiAuthenticationException extends Exception
{
    public function __construct(
        string $message = 'Authentication failed',
        public readonly string $errorCode = 'authentication_failed',
        public readonly string $errorType = 'authentication_error',
    ) {
        parent::__construct($message);
    }
}
```

```php
<?php
// packages/streeboga/payment-data/src/Exceptions/PaymentException.php
namespace Streeboga\PaymentData\Exceptions;

use Exception;

class PaymentException extends Exception
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'payment_error',
        public readonly string $errorType = 'invalid_request_error',
        public readonly int $httpStatus = 400,
    ) {
        parent::__construct($message);
    }
}
```

```php
<?php
// packages/streeboga/payment-data/src/Exceptions/ConnectorException.php
namespace Streeboga\PaymentData\Exceptions;

use Exception;

class ConnectorException extends Exception
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'connector_error',
        public readonly string $errorType = 'connector_error',
        public readonly ?string $connector = null,
    ) {
        parent::__construct($message);
    }
}
```

```php
<?php
// packages/streeboga/payment-data/src/Exceptions/InvalidStateTransitionException.php
namespace Streeboga\PaymentData\Exceptions;

class InvalidStateTransitionException extends PaymentException
{
    public function __construct(string $from, string $to)
    {
        parent::__construct("Invalid status transition from '{$from}' to '{$to}'", 'invalid_state_transition', 'invalid_request_error', 400);
    }
}
```

**Шаг 7: ConnectorInterface**

```php
<?php
// packages/streeboga/payment-data/src/Contracts/ConnectorInterface.php
namespace Streeboga\PaymentData\Contracts;

interface ConnectorInterface
{
    public function authorize(array $params): array;
    public function purchase(array $params): array;
    public function capture(array $params): array;
    public function refund(array $params): array;
    public function getName(): string;
}
```

**Шаг 8: Запустить все тесты**

```bash
./vendor/bin/pest tests/Unit/Support/
```

**Шаг 9: Коммит**

```bash
git add -A
git commit -m "feat(payment-data): add IdGenerator, WebhookSigner, exceptions, ConnectorInterface"
```

---

## Задача 4: payment-data — StateMachine

**Файлы:**
- Create: `packages/streeboga/payment-data/src/StateMachine/PaymentStateMachine.php`
- Test: `tests/Unit/StateMachine/PaymentStateMachineTest.php`

**Шаг 1: Тест**

```php
<?php
// tests/Unit/StateMachine/PaymentStateMachineTest.php

use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\StateMachine\PaymentStateMachine;
use Streeboga\PaymentData\Exceptions\InvalidStateTransitionException;

test('requires_payment_method → requires_confirmation is valid', function () {
    expect(PaymentStateMachine::canTransition(PaymentStatus::REQUIRES_PAYMENT_METHOD, PaymentStatus::REQUIRES_CONFIRMATION))->toBeTrue();
});

test('requires_confirmation → processing is valid', function () {
    expect(PaymentStateMachine::canTransition(PaymentStatus::REQUIRES_CONFIRMATION, PaymentStatus::PROCESSING))->toBeTrue();
});

test('requires_confirmation → requires_capture is valid', function () {
    expect(PaymentStateMachine::canTransition(PaymentStatus::REQUIRES_CONFIRMATION, PaymentStatus::REQUIRES_CAPTURE))->toBeTrue();
});

test('processing → succeeded is valid', function () {
    expect(PaymentStateMachine::canTransition(PaymentStatus::PROCESSING, PaymentStatus::SUCCEEDED))->toBeTrue();
});

test('requires_capture → succeeded is valid', function () {
    expect(PaymentStateMachine::canTransition(PaymentStatus::REQUIRES_CAPTURE, PaymentStatus::SUCCEEDED))->toBeTrue();
});

test('any non-terminal → cancelled is valid', function () {
    expect(PaymentStateMachine::canTransition(PaymentStatus::REQUIRES_PAYMENT_METHOD, PaymentStatus::CANCELLED))->toBeTrue();
    expect(PaymentStateMachine::canTransition(PaymentStatus::REQUIRES_CONFIRMATION, PaymentStatus::CANCELLED))->toBeTrue();
    expect(PaymentStateMachine::canTransition(PaymentStatus::REQUIRES_CAPTURE, PaymentStatus::CANCELLED))->toBeTrue();
});

test('succeeded → cancelled is invalid', function () {
    expect(PaymentStateMachine::canTransition(PaymentStatus::SUCCEEDED, PaymentStatus::CANCELLED))->toBeFalse();
});

test('failed → succeeded is invalid', function () {
    expect(PaymentStateMachine::canTransition(PaymentStatus::FAILED, PaymentStatus::SUCCEEDED))->toBeFalse();
});

test('terminal statuses have no outgoing transitions', function () {
    expect(PaymentStateMachine::allowedTransitions(PaymentStatus::SUCCEEDED))->toBeEmpty();
    expect(PaymentStateMachine::allowedTransitions(PaymentStatus::CANCELLED))->toBeEmpty();
    expect(PaymentStateMachine::allowedTransitions(PaymentStatus::EXPIRED))->toBeEmpty();
    expect(PaymentStateMachine::allowedTransitions(PaymentStatus::FAILED))->toBeEmpty();
});

test('assertTransition throws on invalid', function () {
    PaymentStateMachine::assertTransition(PaymentStatus::SUCCEEDED, PaymentStatus::CANCELLED);
})->throws(InvalidStateTransitionException::class);

test('assertTransition passes on valid', function () {
    PaymentStateMachine::assertTransition(PaymentStatus::PROCESSING, PaymentStatus::SUCCEEDED);
    expect(true)->toBeTrue(); // no exception
});
```

**Шаг 2: Запустить — убедиться что падает**

```bash
./vendor/bin/pest tests/Unit/StateMachine/
```

**Шаг 3: Реализовать**

```php
<?php
// packages/streeboga/payment-data/src/StateMachine/PaymentStateMachine.php

namespace Streeboga\PaymentData\StateMachine;

use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Exceptions\InvalidStateTransitionException;

class PaymentStateMachine
{
    private static array $transitions = [
        'requires_payment_method' => ['requires_confirmation', 'processing', 'cancelled', 'expired'],
        'requires_confirmation' => ['processing', 'requires_capture', 'requires_customer_action', 'cancelled', 'expired', 'failed'],
        'requires_customer_action' => ['processing', 'cancelled', 'expired', 'failed'],
        'requires_merchant_action' => ['processing', 'cancelled', 'failed'],
        'processing' => ['succeeded', 'failed', 'requires_capture', 'requires_customer_action'],
        'requires_capture' => ['succeeded', 'partially_captured', 'partially_captured_and_capturable', 'cancelled'],
        'partially_captured_and_capturable' => ['succeeded', 'partially_captured'],
        'succeeded' => [],
        'failed' => [],
        'cancelled' => [],
        'expired' => [],
        'partially_captured' => [],
    ];

    public static function canTransition(PaymentStatus $from, PaymentStatus $to): bool
    {
        return in_array($to->value, self::$transitions[$from->value] ?? [], true);
    }

    public static function allowedTransitions(PaymentStatus $from): array
    {
        return array_map(
            fn (string $s) => PaymentStatus::from($s),
            self::$transitions[$from->value] ?? []
        );
    }

    public static function assertTransition(PaymentStatus $from, PaymentStatus $to): void
    {
        if (! self::canTransition($from, $to)) {
            throw new InvalidStateTransitionException($from->value, $to->value);
        }
    }
}
```

**Шаг 4: Запустить тесты**

```bash
./vendor/bin/pest tests/Unit/StateMachine/
```

**Шаг 5: Коммит**

```bash
git add -A
git commit -m "feat(payment-data): implement PaymentStateMachine with transition guards"
```

---

## Задача 5: payment-data — Миграции (tenant hierarchy)

**Файлы (в `packages/streeboga/payment-data/database/migrations/`):**
- Create: `2026_03_18_000001_create_organizations_table.php`
- Create: `2026_03_18_000002_create_merchant_accounts_table.php`
- Create: `2026_03_18_000003_create_business_profiles_table.php`
- Create: `2026_03_18_000004_create_api_keys_table.php`

**Файлы (в `packages/streeboga/payment-data/src/Models/`):**
- Create: `Organization.php`
- Create: `MerchantAccount.php`
- Create: `BusinessProfile.php`
- Create: `ApiKey.php`

**Test:** `tests/Unit/Models/TenantHierarchyTest.php`

Миграции по схеме из Architecture. Модели с relationships, casts, fillable. ApiKey: методы `isRevoked()`, `isExpired()`, `revoke()`. Тесты: создание иерархии, relationships, revoke API key.

**Коммит:**
```bash
git commit -m "feat(payment-data): add tenant hierarchy — organizations, merchants, profiles, API keys"
```

---

## Задача 6: payment-data — Миграции (платёжное ядро + коннекторы)

**Файлы (в `packages/streeboga/payment-data/database/migrations/`):**
- Create: `2026_03_18_000005_create_merchant_connector_accounts_table.php`
- Create: `2026_03_18_000006_create_payment_intents_table.php`
- Create: `2026_03_18_000007_create_payment_attempts_table.php`
- Create: `2026_03_18_000008_create_payment_audit_log_table.php`

**Файлы (в `packages/streeboga/payment-data/src/Models/`):**
- Create: `MerchantConnectorAccount.php`
- Create: `PaymentIntent.php` — casts для PaymentStatus, CaptureMethod enum. Encrypted `connector_account_details`. `lockForUpdate()` scope для race condition prevention.
- Create: `PaymentAttempt.php`
- Create: `PaymentAuditLog.php`

**Test:** `tests/Unit/Models/PaymentModelsTest.php`

**ВАЖНО (race conditions):** PaymentIntent модель должна иметь scope:
```php
public function scopeLocked($query)
{
    return $query->lockForUpdate();
}
```
Все state transitions в Actions должны использовать `PaymentIntent::where('payment_id', $id)->locked()->firstOrFail()`.

**Коммит:**
```bash
git commit -m "feat(payment-data): add payment core models — intents, attempts, audit log, connectors"
```

---

## Задача 7: payment-data — Миграции (customers, refunds, webhooks)

**Файлы (в `packages/streeboga/payment-data/database/migrations/`):**
- Create: `2026_03_18_000009_create_customers_table.php`
- Create: `2026_03_18_000010_create_refunds_table.php`
- Create: `2026_03_18_000011_create_webhook_events_table.php`

**Файлы (в `packages/streeboga/payment-data/src/Models/`):**
- Create: `Customer.php`
- Create: `Refund.php`
- Create: `WebhookEvent.php`

**Test:** `tests/Unit/Models/CustomerRefundWebhookTest.php`

**Коммит:**
```bash
git commit -m "feat(payment-data): add customers, refunds, webhook_events models and migrations"
```

---

## Задача 8: payment-connectors — Abstraction layer

**Файлы (в `packages/streeboga/payment-connectors/src/`):**
- Create: `AbstractConnector.php` — инициализация OmniPay gateway из encrypted credentials
- Create: `ConnectorFactory.php` — resolve коннектора по имени из MerchantConnectorAccount
- Create: `ConnectorErrorNormalizer.php` — маппинг PSP-ошибок в Hyperswitch формат
- Test: `tests/Unit/Connectors/ConnectorFactoryTest.php`

**Коммит:**
```bash
git commit -m "feat(payment-connectors): add OmniPay abstraction layer and ConnectorFactory"
```

---

## Задача 9: payment-connectors — Stripe driver

**Файлы:**
- Create: `packages/streeboga/payment-connectors/src/Drivers/StripeConnector.php`
- Test: `tests/Unit/Connectors/StripeConnectorTest.php`

StripeConnector extends AbstractConnector, маппит authorize/purchase/capture/refund на OmniPay Stripe gateway. Тесты мокают OmniPay gateway.

**Коммит:**
```bash
git commit -m "feat(payment-connectors): add Stripe connector driver"
```

---

## Задача 10: payment-connectors — YooKassa driver

**Файлы:**
- Create: `packages/streeboga/payment-connectors/src/Drivers/YooKassaConnector.php`
- Test: `tests/Unit/Connectors/YooKassaConnectorTest.php`

Аналогично Stripe, но с YooKassa-специфичной аутентификацией (shop_id + secret_key). Если OmniPay driver для YooKassa не существует — реализовать minimal custom gateway.

**Коммит:**
```bash
git commit -m "feat(payment-connectors): add YooKassa connector driver"
```

---

## Задача 11: payment-connectors — Incoming webhook handler

**Файлы:**
- Create: `packages/streeboga/payment-connectors/src/Webhooks/IncomingWebhookHandler.php`
- Create: `packages/streeboga/payment-connectors/src/Webhooks/StripeWebhookParser.php`
- Create: `packages/streeboga/payment-connectors/src/Webhooks/YooKassaWebhookParser.php`
- Test: `tests/Unit/Webhooks/IncomingWebhookHandlerTest.php`

Обработка входящих webhook-ов от PSP (Stripe/YooKassa). Верификация подписи, парсинг payload, маппинг на PaymentStatus transitions.

**Коммит:**
```bash
git commit -m "feat(payment-connectors): add incoming webhook handler for PSP notifications"
```

---

## Задача 12: Laravel app — Error handler и Hyperswitch error format

**Файлы:**
- Modify: `bootstrap/app.php` — exception rendering для API
- Test: `tests/Feature/Api/ErrorResponseTest.php`

Все exceptions из `Streeboga\PaymentData\Exceptions\*` рендерятся в Hyperswitch JSON формат: `{"error": {"type": "...", "code": "...", "message": "..."}}`. Также: NotFoundHttpException, ValidationException, AuthenticationException.

**Коммит:**
```bash
git commit -m "feat: add Hyperswitch-compatible error response handler"
```

---

## Задача 13: Laravel app — API key middleware + rate limiting

**Файлы:**
- Create: `app/Http/Middleware/ResolveApiKey.php` — парсинг api-key header, lookup по prefix, bcrypt verify, merchant context
- Create: `app/Http/Middleware/AuthenticateAdminApiKey.php` — пропускает только admin key type
- Create: `app/Http/Middleware/AuthenticateSecretApiKey.php` — пропускает только secret key type
- Modify: `bootstrap/app.php` — middleware aliases + rate limiting config
- Modify: `routes/api.php` — middleware groups
- Test: `tests/Feature/Api/Auth/ApiKeyAuthTest.php`
- Test: `tests/Feature/Api/Auth/RateLimitTest.php`

Rate limiting через Laravel `RateLimiter` в AppServiceProvider:
```php
RateLimiter::for('payswitch-api', function (Request $request) {
    $type = $request->attributes->get('api_key_type', 'unknown');
    $limit = config("payswitch.rate_limit.{$type}", 60);
    $key = $request->attributes->get('merchant_id', $request->ip());
    return Limit::perMinute($limit)->by($key);
});
```

**Коммит:**
```bash
git commit -m "feat: add API key auth middleware and rate limiting"
```

---

## Задача 14: Admin API — организации и мерчант-аккаунты

**Файлы:**
- Create: `app/Http/Controllers/Api/Admin/OrganizationController.php`
- Create: `app/Http/Controllers/Api/Admin/MerchantAccountController.php`
- Create: `app/Http/Requests/Api/Admin/CreateOrganizationRequest.php`
- Create: `app/Http/Requests/Api/Admin/CreateMerchantAccountRequest.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/Admin/OrganizationTest.php`
- Test: `tests/Feature/Api/Admin/MerchantAccountTest.php`

**Коммит:**
```bash
git commit -m "feat: add Admin API — organization and merchant account endpoints"
```

---

## Задача 15: Admin API — профили, API-ключи, коннекторы

**Файлы:**
- Create: `app/Http/Controllers/Api/Admin/BusinessProfileController.php`
- Create: `app/Http/Controllers/Api/Admin/ApiKeyController.php`
- Create: `app/Http/Controllers/Api/Admin/ConnectorController.php`
- Create: `app/Http/Requests/Api/Admin/CreateBusinessProfileRequest.php`
- Create: `app/Http/Requests/Api/Admin/CreateApiKeyRequest.php`
- Create: `app/Http/Requests/Api/Admin/CreateConnectorRequest.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/Admin/BusinessProfileTest.php`
- Test: `tests/Feature/Api/Admin/ApiKeyTest.php`
- Test: `tests/Feature/Api/Admin/ConnectorTest.php`

**Коммит:**
```bash
git commit -m "feat: add Admin API — profiles, API keys, connector CRUD"
```

---

## Задача 16: Payment API — create payment

**Файлы:**
- Create: `app/Http/Controllers/Api/PaymentController.php` (метод `store`)
- Create: `app/Http/Requests/Api/CreatePaymentRequest.php`
- Create: `app/Actions/Payswitch/Payments/CreatePaymentAction.php`
- Create: `app/Events/PaymentStatusChanged.php`
- Create: `app/Listeners/LogPaymentAudit.php`
- Modify: `routes/api.php`
- Modify: `app/Providers/AppServiceProvider.php` — event → listener binding
- Test: `tests/Feature/Api/Payments/CreatePaymentTest.php`

CreatePaymentAction: генерирует payment_id, client_secret через IdGenerator, создаёт PaymentIntent со статусом `requires_payment_method`, fires PaymentStatusChanged event.

**Коммит:**
```bash
git commit -m "feat: add POST /payments — create PaymentIntent"
```

---

## Задача 17: Payment API — confirm payment

**Файлы:**
- Create: `app/Http/Requests/Api/ConfirmPaymentRequest.php`
- Create: `app/Actions/Payswitch/Payments/ConfirmPaymentAction.php`
- Create: `app/Services/RoutingService.php`
- Create: `app/Services/ConnectorService.php`
- Modify: `app/Http/Controllers/Api/PaymentController.php` (метод `confirm`)
- Test: `tests/Feature/Api/Payments/ConfirmPaymentTest.php`

ConfirmPaymentAction: `lockForUpdate()` на PaymentIntent, resolve connector через RoutingService, вызов purchase()/authorize() через ConnectorService, state transition через PaymentStateMachine, создание PaymentAttempt. Поддержка `confirm: true` при create.

**Коммит:**
```bash
git commit -m "feat: add POST /payments/{id}/confirm — confirm payment with PSP"
```

---

## Задача 18: Payment API — capture, cancel, get

**Файлы:**
- Create: `app/Actions/Payswitch/Payments/CapturePaymentAction.php`
- Create: `app/Actions/Payswitch/Payments/CancelPaymentAction.php`
- Create: `app/Http/Requests/Api/CapturePaymentRequest.php`
- Modify: `app/Http/Controllers/Api/PaymentController.php` (методы `capture`, `cancel`, `show`)
- Test: `tests/Feature/Api/Payments/CapturePaymentTest.php`
- Test: `tests/Feature/Api/Payments/CancelPaymentTest.php`
- Test: `tests/Feature/Api/Payments/GetPaymentTest.php`

Все Actions используют `lockForUpdate()` + `PaymentStateMachine::assertTransition()`.

**Коммит:**
```bash
git commit -m "feat: add capture, cancel, get payment endpoints"
```

---

## Задача 19: Customer CRUD

**Файлы:**
- Create: `app/Http/Controllers/Api/CustomerController.php`
- Create: `app/Http/Requests/Api/CreateCustomerRequest.php`
- Create: `app/Http/Requests/Api/UpdateCustomerRequest.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/Customers/CustomerCrudTest.php`

Customers scoped по merchant_id из middleware context.

**Коммит:**
```bash
git commit -m "feat: add customer CRUD endpoints"
```

---

## Задача 20: Refunds

**Файлы:**
- Create: `app/Http/Controllers/Api/RefundController.php`
- Create: `app/Http/Requests/Api/CreateRefundRequest.php`
- Create: `app/Actions/Payswitch/Refunds/CreateRefundAction.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/Refunds/CreateRefundTest.php`
- Test: `tests/Feature/Api/Refunds/GetRefundTest.php`

CreateRefundAction: найти PaymentIntent, проверить статус succeeded, найти connector из последнего успешного PaymentAttempt, вызвать refund(), создать Refund.

**Коммит:**
```bash
git commit -m "feat: add refund create and get endpoints"
```

---

## Задача 21: Routing Service — fallback

**Файлы:**
- Modify: `app/Services/RoutingService.php`
- Test: `tests/Unit/Services/RoutingServiceTest.php`

RoutingService: explicit connector → auto-select by payment_method+currency → priority fallback при ошибке. Тесты: explicit routing, auto-select, fallback на second connector.

**Коммит:**
```bash
git commit -m "feat: add priority-based connector routing with fallback"
```

---

## Задача 22: Webhook Engine — outgoing

**Файлы:**
- Create: `app/Listeners/SendWebhookNotification.php`
- Create: `app/Jobs/DeliverWebhookJob.php`
- Create: `app/Services/WebhookService.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Api/Webhooks/WebhookDeliveryTest.php`
- Test: `tests/Unit/Jobs/DeliverWebhookJobTest.php`

SendWebhookNotification слушает PaymentStatusChanged → создаёт WebhookEvent → dispatch DeliverWebhookJob. DeliverWebhookJob: HTTP POST с HMAC-SHA512 подписью, retry schedule из config.

**Коммит:**
```bash
git commit -m "feat: add webhook engine — event creation, HMAC signing, retry delivery"
```

---

## Задача 23: Webhook Engine — incoming от PSP

**Файлы:**
- Create: `app/Http/Controllers/Api/WebhookReceiverController.php`
- Modify: `routes/api.php` — route без auth middleware
- Test: `tests/Feature/Api/Webhooks/IncomingWebhookTest.php`

Эндпоинт `POST /api/v1/webhooks/{merchant_id}/{mca_id}` — принимает webhook от PSP, верифицирует подпись через connector-specific parser, обновляет PaymentIntent status через state machine.

**Коммит:**
```bash
git commit -m "feat: add incoming webhook receiver from PSP (Stripe, YooKassa)"
```

---

## Задача 24: Seed-команда и OpenAPI документация

**Файлы:**
- Create: `app/Console/Commands/PayswitchSeedCommand.php`
- Modify: `composer.json` — добавить script `payswitch:setup`
- Test: `tests/Feature/Console/PayswitchSeedCommandTest.php`

`php artisan payswitch:seed` создаёт: demo organization → merchant account → business profile → API key (выводит в консоль) → Stripe connector (test mode). Scramble авто-генерирует OpenAPI spec на `/docs/api`.

**Коммит:**
```bash
git commit -m "feat: add payswitch:seed command and Scramble OpenAPI docs"
```

---

## Задача 25: Интеграционный тест полного flow

**Файлы:**
- Test: `tests/Feature/Api/FullPaymentFlowTest.php`

Один тест, полный цикл:
1. Create organization (Admin API)
2. Create merchant account
3. Create business profile с webhook URL
4. Create API key → получить raw key
5. Add Stripe connector
6. Create payment (Secret API Key)
7. Confirm payment
8. Verify status = succeeded
9. Create partial refund
10. Verify refund status
11. Verify WebhookEvent записи существуют
12. Verify PaymentAuditLog записи существуют

**Коммит:**
```bash
git commit -m "test: add full payment flow integration test — org to refund"
```
