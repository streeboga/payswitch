# Доработки плана под Laravel API Architecture + JSON:API v1.1

> Применяется поверх `docs/plans/2026-03-18-payswitch-mvp.md`.
> Это финальная версия архитектурных решений.

## Решение: JSON:API v1.1 + Scramble

**Формат API:** JSON:API v1.1 ([jsonapi.org](https://jsonapi.org))
**Документация:** Scramble (автогенерация OpenAPI из кода)
**Hyperswitch wire-compatibility:** Отменена. Используем стандарт JSON:API.

### Что это меняет

| Аспект | Было (Hyperswitch) | Стало (JSON:API v1.1) |
|--------|--------------------|-----------------------|
| Content-Type | `application/json` | `application/vnd.api+json` |
| Ответ единичный | `{payment_id, status, amount, ...}` | `{data: {type: "payments", id: "pay_...", attributes: {status, amount, ...}}}` |
| Ответ коллекция | `[{...}, {...}]` | `{data: [...], meta: {current_page, per_page, total}, links: {first, last, prev, next}}` |
| Ошибки | `{error: {type, code, message}}` | `{errors: [{status: "401", code: "api_key_missing", title: "...", detail: "..."}]}` |
| Создание (запрос) | `{amount: 6540, currency: "USD"}` | `{data: {type: "payments", attributes: {amount: 6540, currency: "USD"}}}` |
| Обновление | `POST /customers/{id}` | `PATCH /customers/{id}` |
| Удаление | `200 + body` | `204 No Content` |
| Фильтрация | `?status=active` | `?filter[status]=active` |
| Пагинация | `?page=1&per_page=20` | `?page[number]=1&page[size]=20` |
| Сортировка | — | `?sort=-created_at,amount` |
| Включение связей | — | `?include=attempts,refunds` |

## Зависимости — финальный список

### Root composer.json — добавить:
```json
{
    "require": {
        "streeboga/payment-data": "*",
        "streeboga/payment-connectors": "*",
        "timacdonald/json-api": "^1.0",
        "spatie/laravel-data": "^4.0",
        "spatie/laravel-query-builder": "^6.0",
        "dedoc/scramble": "^0.12"
    }
}
```

### payment-data composer.json — добавить:
```json
{
    "require": {
        "spatie/laravel-data": "^4.0"
    }
}
```

## Полная структура app/

```
app/
├── Builders/                           # QueryBuilder классы
│   ├── PaymentIntentQueryBuilder.php
│   ├── CustomerQueryBuilder.php
│   ├── RefundQueryBuilder.php
│   ├── MerchantConnectorQueryBuilder.php
│   └── WebhookEventQueryBuilder.php
├── Console/Commands/
│   └── PayswitchSeedCommand.php
├── Contracts/Enums/                    # Enum interfaces
│   ├── HasLabel.php
│   ├── HasColor.php
│   └── HasIcon.php
├── DataTransferObjects/               # DTOs (spatie/laravel-data)
│   ├── Payment/
│   │   ├── CreatePaymentData.php
│   │   ├── ConfirmPaymentData.php
│   │   └── CapturePaymentData.php
│   ├── Refund/
│   │   └── CreateRefundData.php
│   ├── Customer/
│   │   ├── CreateCustomerData.php
│   │   └── UpdateCustomerData.php
│   └── Admin/
│       ├── CreateOrganizationData.php
│       ├── CreateMerchantAccountData.php
│       ├── CreateBusinessProfileData.php
│       ├── CreateApiKeyData.php
│       └── CreateConnectorData.php
├── Events/
│   └── PaymentStatusChanged.php
├── Http/
│   ├── Controllers/Api/V1/            # Тонкие контроллеры (versioned!)
│   │   ├── PaymentController.php
│   │   ├── RefundController.php
│   │   ├── CustomerController.php
│   │   ├── WebhookReceiverController.php
│   │   └── Admin/
│   │       ├── OrganizationController.php
│   │       ├── MerchantAccountController.php
│   │       ├── BusinessProfileController.php
│   │       ├── ApiKeyController.php
│   │       └── ConnectorController.php
│   ├── Middleware/
│   │   ├── ResolveApiKey.php
│   │   ├── AuthenticateAdminApiKey.php
│   │   ├── AuthenticateSecretApiKey.php
│   │   └── ForceJsonApiContentType.php  # NEW
│   ├── Requests/Api/
│   │   ├── Payment/
│   │   │   ├── StorePaymentRequest.php      # Store, не Create (Laravel convention)
│   │   │   ├── ConfirmPaymentRequest.php
│   │   │   └── CapturePaymentRequest.php
│   │   ├── Refund/
│   │   │   └── StoreRefundRequest.php
│   │   ├── Customer/
│   │   │   ├── StoreCustomerRequest.php
│   │   │   └── UpdateCustomerRequest.php
│   │   └── Admin/
│   │       ├── StoreOrganizationRequest.php
│   │       ├── StoreMerchantAccountRequest.php
│   │       ├── StoreBusinessProfileRequest.php
│   │       ├── StoreApiKeyRequest.php
│   │       └── StoreConnectorRequest.php
│   └── Resources/                      # JSON:API Resources
│       ├── PaymentIntentResource.php    # extends JsonApiResource
│       ├── PaymentAttemptResource.php
│       ├── RefundResource.php
│       ├── CustomerResource.php
│       ├── OrganizationResource.php
│       ├── MerchantAccountResource.php
│       ├── BusinessProfileResource.php
│       ├── ApiKeyResource.php
│       └── ConnectorResource.php
├── Jobs/
│   ├── DeliverWebhookJob.php
│   └── ExpireStalePaymentsJob.php
├── Listeners/
│   ├── LogPaymentAudit.php
│   └── SendWebhookNotification.php
├── Providers/
│   └── RepositoryServiceProvider.php
├── Repositories/
│   ├── Contracts/
│   │   ├── PaymentIntentRepositoryInterface.php
│   │   ├── RefundRepositoryInterface.php
│   │   ├── CustomerRepositoryInterface.php
│   │   ├── MerchantRepositoryInterface.php
│   │   └── WebhookEventRepositoryInterface.php
│   └── Eloquent/
│       ├── PaymentIntentRepository.php
│       ├── RefundRepository.php
│       ├── CustomerRepository.php
│       ├── MerchantRepository.php
│       └── WebhookEventRepository.php
└── Services/
    ├── PaymentService.php
    ├── RefundService.php
    ├── MerchantService.php
    ├── ApiKeyService.php
    ├── ConnectorService.php
    ├── RoutingService.php
    └── WebhookService.php
```

## JSON:API Resources — примеры

### PaymentIntentResource

```php
<?php
declare(strict_types=1);

namespace App\Http\Resources;

use TiMacDonald\JsonApi\JsonApiResource;
use TiMacDonald\JsonApi\Link;

final class PaymentIntentResource extends JsonApiResource
{
    public function toId($request): string
    {
        return $this->payment_id;
    }

    public function toType($request): string
    {
        return 'payments';
    }

    public function toAttributes($request): array
    {
        return [
            'status' => $this->status->value,
            'amount' => $this->amount,
            'net_amount' => $this->net_amount,
            'amount_capturable' => $this->amount_capturable,
            'amount_received' => $this->amount_received,
            'currency' => $this->currency,
            'client_secret' => $this->client_secret,
            'capture_method' => $this->capture_method->value,
            'authentication_type' => $this->authentication_type->value,
            'customer_id' => $this->customer_id,
            'description' => $this->description,
            'return_url' => $this->return_url,
            'metadata' => $this->metadata,
            'connector' => $this->connector,
            'attempt_count' => $this->attempt_count,
            'profile_id' => $this->profile_id,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'cancellation_reason' => $this->cancellation_reason,
            'session_expiry' => $this->session_expiry,
            'created_at' => $this->created_at?->toIso8601String(),
            'expires_on' => $this->expires_on?->toIso8601String(),
        ];
    }

    public function toRelationships($request): array
    {
        return [
            'attempts' => fn () => PaymentAttemptResource::collection($this->attempts),
            'refunds' => fn () => RefundResource::collection($this->refunds),
            'merchant' => fn () => MerchantAccountResource::make($this->merchantAccount),
        ];
    }

    public function toLinks($request): array
    {
        return [
            Link::self(route('api.v1.payments.show', $this->payment_id)),
        ];
    }
}
```

### Ответ на GET /api/v1/payments/pay_01JEXAMPLE

```json
{
    "data": {
        "type": "payments",
        "id": "pay_01JGW2N7KBZV8QJM...",
        "attributes": {
            "status": "requires_payment_method",
            "amount": 6540,
            "currency": "USD",
            "client_secret": "pay_01J..._secret_01J...",
            "capture_method": "automatic",
            "attempt_count": 1,
            "created_at": "2026-03-18T10:00:00Z"
        },
        "relationships": {
            "attempts": {
                "data": []
            },
            "merchant": {
                "data": { "type": "merchants", "id": "merchant_01J..." }
            }
        },
        "links": {
            "self": "https://api.example.com/api/v1/payments/pay_01J..."
        }
    }
}
```

### Ошибки (JSON:API формат)

```php
// В exception handler (bootstrap/app.php)
$exceptions->render(function (ApiAuthenticationException $e, $request) {
    if ($request->is('api/*')) {
        return response()->json([
            'errors' => [[
                'status' => '401',
                'code' => $e->errorCode,
                'title' => 'Authentication Error',
                'detail' => $e->getMessage(),
            ]],
        ], 401)->header('Content-Type', 'application/vnd.api+json');
    }
});

$exceptions->render(function (PaymentException $e, $request) {
    if ($request->is('api/*')) {
        return response()->json([
            'errors' => [[
                'status' => (string) $e->httpStatus,
                'code' => $e->errorCode,
                'title' => $e->errorType,
                'detail' => $e->getMessage(),
            ]],
        ], $e->httpStatus)->header('Content-Type', 'application/vnd.api+json');
    }
});

$exceptions->render(function (ValidationException $e, $request) {
    if ($request->is('api/*')) {
        $errors = collect($e->errors())->flatMap(fn ($messages, $field) =>
            collect($messages)->map(fn ($msg) => [
                'status' => '422',
                'code' => 'validation_error',
                'title' => 'Validation Error',
                'detail' => $msg,
                'source' => ['pointer' => "/data/attributes/{$field}"],
            ])
        )->values()->toArray();

        return response()->json(['errors' => $errors], 422)
            ->header('Content-Type', 'application/vnd.api+json');
    }
});
```

### ForceJsonApiContentType middleware

```php
<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

final class ForceJsonApiContentType
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        $response->headers->set('Content-Type', 'application/vnd.api+json');
        return $response;
    }
}
```

## Контроллер — тонкий, JSON:API style

```php
<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\Payment\StorePaymentRequest;
use App\Http\Requests\Api\Payment\ConfirmPaymentRequest;
use App\Http\Requests\Api\Payment\CapturePaymentRequest;
use App\Http\Resources\PaymentIntentResource;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;

final class PaymentController
{
    public function __construct(
        private PaymentService $paymentService,
    ) {}

    /**
     * Create a new payment intent.
     *
     * Creates a PaymentIntent with status requires_payment_method.
     */
    public function store(StorePaymentRequest $request): JsonResponse
    {
        $payment = $this->paymentService->create(
            $request->toDto(),
            $request->attributes->get('merchant_id'),
        );

        return PaymentIntentResource::make($payment)
            ->toResponse($request)
            ->setStatusCode(201)
            ->withHeaders([
                'Location' => route('api.v1.payments.show', $payment->payment_id),
            ]);
    }

    /**
     * Retrieve a payment intent.
     */
    public function show(string $paymentId): PaymentIntentResource
    {
        return PaymentIntentResource::make(
            $this->paymentService->find($paymentId)
        );
    }

    /**
     * Confirm a payment with payment method data.
     */
    public function confirm(string $paymentId, ConfirmPaymentRequest $request): PaymentIntentResource
    {
        return PaymentIntentResource::make(
            $this->paymentService->confirm($paymentId, $request->toDto())
        );
    }

    /**
     * Capture authorized funds.
     */
    public function capture(string $paymentId, CapturePaymentRequest $request): PaymentIntentResource
    {
        return PaymentIntentResource::make(
            $this->paymentService->capture($paymentId, $request->validated('amount_to_capture'))
        );
    }

    /**
     * Cancel a payment.
     */
    public function cancel(string $paymentId): PaymentIntentResource
    {
        return PaymentIntentResource::make(
            $this->paymentService->cancel($paymentId)
        );
    }
}
```

## Routes — JSON:API style

```php
// routes/api.php
Route::prefix('v1')->middleware(['auth.api_key', 'json-api'])->group(function () {

    Route::get('/health', HealthController::class)->withoutMiddleware(['auth.api_key']);

    // Admin API
    Route::middleware('auth.admin_api_key')->group(function () {
        Route::post('/organizations', [OrganizationController::class, 'store']);
        Route::post('/merchants', [MerchantAccountController::class, 'store']);
        Route::get('/merchants/{merchantId}', [MerchantAccountController::class, 'show']);
        Route::post('/profiles', [BusinessProfileController::class, 'store']);
        Route::get('/profiles/{profileId}', [BusinessProfileController::class, 'show']);
        Route::post('/merchants/{merchantId}/api-keys', [ApiKeyController::class, 'store']);
        Route::delete('/merchants/{merchantId}/api-keys/{keyId}', [ApiKeyController::class, 'destroy']);
        Route::post('/merchants/{merchantId}/connectors', [ConnectorController::class, 'store']);
        Route::get('/merchants/{merchantId}/connectors', [ConnectorController::class, 'index']);
        Route::get('/merchants/{merchantId}/connectors/{connectorId}', [ConnectorController::class, 'show']);
        Route::patch('/merchants/{merchantId}/connectors/{connectorId}', [ConnectorController::class, 'update']);
        Route::delete('/merchants/{merchantId}/connectors/{connectorId}', [ConnectorController::class, 'destroy']);
    });

    // Merchant API
    Route::middleware('auth.secret_api_key')->group(function () {
        Route::post('/payments', [PaymentController::class, 'store'])->name('api.v1.payments.store');
        Route::get('/payments/{paymentId}', [PaymentController::class, 'show'])->name('api.v1.payments.show');
        Route::post('/payments/{paymentId}/confirm', [PaymentController::class, 'confirm']);
        Route::post('/payments/{paymentId}/capture', [PaymentController::class, 'capture']);
        Route::post('/payments/{paymentId}/cancel', [PaymentController::class, 'cancel']);

        Route::post('/refunds', [RefundController::class, 'store']);
        Route::get('/refunds/{refundId}', [RefundController::class, 'show']);

        Route::post('/customers', [CustomerController::class, 'store']);
        Route::get('/customers/{customerId}', [CustomerController::class, 'show']);
        Route::patch('/customers/{customerId}', [CustomerController::class, 'update']);  // PATCH!
        Route::delete('/customers/{customerId}', [CustomerController::class, 'destroy']);
    });

    // Incoming PSP webhooks (no auth)
    Route::post('/webhooks/{merchantId}/{mcaId}', [WebhookReceiverController::class, 'handle'])
        ->withoutMiddleware(['auth.api_key']);
});
```

## Обновлённый список задач (дельта)

| Задача | Добавить |
|--------|----------|
| 1 | `timacdonald/json-api`, `spatie/laravel-data`, `spatie/laravel-query-builder`, `dedoc/scramble` в deps |
| 1 | `ForceJsonApiContentType` middleware |
| 2 | Enum interfaces `HasLabel`, `HasColor` в `app/Contracts/Enums/` |
| 3 | Переименовать `Str::random` → `Str::ulid` в IdGenerator |
| 5-7 | Модели: `getRouteKeyName() → 'key'`, `booted()` auto-generate, без scopes |
| 12 | Error handler: JSON:API errors format `{errors: [{status, code, title, detail}]}` |
| 14-15 | `Store*Request` (не `Create*Request`). DTOs с `toDto()`. Resources как `JsonApiResource`. `201 + Location`. Контроллеры в `Api/V1/` |
| 16-18 | `PaymentService` вместо Actions. `PaymentIntentResource` extends `JsonApiResource`. Repository + QueryBuilder |
| 19 | `PATCH` для update customer. `204` для delete. `CustomerResource` extends `JsonApiResource` |
| 20 | `RefundResource` extends `JsonApiResource` |
| **NEW** | Задача 1.5: `RepositoryServiceProvider` — bind all interfaces |
| **NEW** | Задача 1.5: `json-api` middleware alias для `ForceJsonApiContentType` |
| Routes | Plural nouns (`/organizations`, `/merchants`), `PATCH` для updates, named routes |
