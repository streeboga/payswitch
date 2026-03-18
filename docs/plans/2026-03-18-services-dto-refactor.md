# Services DTO Refactor — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Все 7 сервисов должны принимать типизированные DTO вместо `array $data`. Создать недостающие DTOs, подключить `toDto()` в FormRequest, обновить контроллеры.

**Architecture:** FormRequest → `toDto()` → Controller → Service(DTO) → Repository. Убрать ручной маппинг `$data['field'] ?? null` — DTO берёт это на себя через Spatie Data. Сервисы получают `final readonly class` где возможно.

**Tech Stack:** `spatie/laravel-data`, PHP 8.3+, Pest

---

## Critical Context

- Существующие DTOs: `CreatePaymentData`, `ConfirmPaymentData`, `CapturePaymentData`, `CreateRefundData`, `CreateCustomerData`, `UpdateCustomerData`, `CreateOrganizationData`, `CreateMerchantAccountData`, `CreateBusinessProfileData`, `CreateApiKeyData`, `CreateConnectorData`
- НЕ созданы: `UpdateConnectorData`, `CreatePaymentMethodData`, `CreateRoutingRuleData`, `UpdateRoutingRuleData`
- Все FormRequests уже имеют `toDto()` метод, но контроллеры вызывают `validatedAttributes()` — нужно переключить на `toDto()`
- Тесты: 276 passed — должны остаться зелёными

---

## Матрица проблем по сервисам

| Сервис | Принимает DTO? | `readonly`? | Валидация дублируется? | Прочие проблемы |
|--------|---------------|-------------|----------------------|-----------------|
| PaymentService | ❌ `array` | ❌ `final` | ✅ amount/currency в service + FormRequest | `app(RoutingService::class)` — service locator |
| RefundService | ❌ `array` | ❌ `final` | ✅ payment_id/amount в service + FormRequest | — |
| CustomerService | ❌ `array` | ❌ `final` | ✅ customId length в service + FormRequest | — |
| PaymentMethodService | ❌ `array` | ❌ `final` | — | `detectBrand` — статический util, не service логика |
| MerchantService | ❌ `array` | ❌ `final` | — | `apiKeys()->findOrFail()` — прямой model access в revokeApiKey |
| ConnectorService | ❌ `array` | ❌ `final` | — | `collect()->only()` для фильтрации |
| WebhookReceiverService | N/A (Request) | ❌ `final` | — | Корректен — принимает Request, это входная точка от PSP |
| RoutingService | N/A | ❌ `final` | — | `app(RoutingService::class)` в PaymentService |

---

## Task 1: Create missing DTOs

**Files:**
- Create: `app/DataTransferObjects/Admin/UpdateConnectorData.php`
- Create: `app/DataTransferObjects/Admin/CreateRoutingRuleData.php`
- Create: `app/DataTransferObjects/Admin/UpdateRoutingRuleData.php`
- Create: `app/DataTransferObjects/PaymentMethod/CreatePaymentMethodData.php`

**Step 1: UpdateConnectorData**

```php
<?php
// app/DataTransferObjects/Admin/UpdateConnectorData.php
declare(strict_types=1);

namespace App\DataTransferObjects\Admin;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

final class UpdateConnectorData extends Data
{
    public function __construct(
        public readonly string|Optional $connector_name = new Optional,
        public readonly string|Optional $connector_type = new Optional,
        public readonly array|Optional|null $connector_account_details = new Optional,
        public readonly array|Optional|null $payment_methods_enabled = new Optional,
        public readonly bool|Optional $test_mode = new Optional,
        public readonly bool|Optional $disabled = new Optional,
        public readonly string|Optional|null $profile_id = new Optional,
    ) {}

    public function toUpdateArray(): array
    {
        return collect($this->toArray())
            ->reject(fn ($value) => $value instanceof Optional)
            ->toArray();
    }
}
```

**Step 2: CreateRoutingRuleData**

```php
<?php
// app/DataTransferObjects/Admin/CreateRoutingRuleData.php
declare(strict_types=1);

namespace App\DataTransferObjects\Admin;

use App\Enums\RoutingRuleType;
use Spatie\LaravelData\Data;

final class CreateRoutingRuleData extends Data
{
    public function __construct(
        public readonly RoutingRuleType $type,
        public readonly string $name,
        public readonly array $rules,
        public readonly bool $active = true,
        public readonly int $priority = 0,
        public readonly ?string $business_profile_id = null,
    ) {}
}
```

**Step 3: UpdateRoutingRuleData**

```php
<?php
// app/DataTransferObjects/Admin/UpdateRoutingRuleData.php
declare(strict_types=1);

namespace App\DataTransferObjects\Admin;

use App\Enums\RoutingRuleType;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

final class UpdateRoutingRuleData extends Data
{
    public function __construct(
        public readonly RoutingRuleType|Optional $type = new Optional,
        public readonly string|Optional $name = new Optional,
        public readonly array|Optional $rules = new Optional,
        public readonly bool|Optional $active = new Optional,
        public readonly int|Optional $priority = new Optional,
        public readonly string|Optional|null $business_profile_id = new Optional,
    ) {}

    public function toUpdateArray(): array
    {
        return collect($this->toArray())
            ->reject(fn ($value) => $value instanceof Optional)
            ->toArray();
    }
}
```

**Step 4: CreatePaymentMethodData**

```php
<?php
// app/DataTransferObjects/PaymentMethod/CreatePaymentMethodData.php
declare(strict_types=1);

namespace App\DataTransferObjects\PaymentMethod;

use Spatie\LaravelData\Data;

final class CreatePaymentMethodData extends Data
{
    public function __construct(
        public readonly string $type,
        public readonly string $connector_name,
        public readonly ?string $card_number = null,
        public readonly ?string $card_last4 = null,
        public readonly ?string $card_brand = null,
        public readonly ?int $card_exp_month = null,
        public readonly ?int $card_exp_year = null,
        public readonly ?string $card_holder_name = null,
        public readonly ?string $connector_token = null,
        public readonly bool $is_default = false,
        public readonly ?array $metadata = null,
    ) {}
}
```

**Step 5: Run tests**

Run: `./vendor/bin/pest`
Expected: All pass (DTOs not wired yet)

**Step 6: Commit**

```
feat: create missing DTOs for connector update, routing rules, payment methods
```

---

## Task 2: Add `toDto()` to FormRequests that lack it

**Files:**
- Modify: `app/Http/Requests/Api/Admin/UpdateConnectorRequest.php` — add `toDto()` returning `UpdateConnectorData`
- Modify: `app/Http/Requests/Api/Admin/StoreRoutingRuleRequest.php` — add `toDto()` returning `CreateRoutingRuleData`
- Modify: `app/Http/Requests/Api/Admin/UpdateRoutingRuleRequest.php` — add `toDto()` returning `UpdateRoutingRuleData`
- Modify: `app/Http/Requests/Api/PaymentMethod/StorePaymentMethodRequest.php` — add `toDto()` returning `CreatePaymentMethodData`

**Pattern for each:**

```php
public function toDto(): UpdateConnectorData
{
    return UpdateConnectorData::from($this->validated('data.attributes') ?? []);
}
```

**Step: Run tests, commit**

```
feat: add toDto() to UpdateConnector, RoutingRule, PaymentMethod FormRequests
```

---

## Task 3: Refactor ConnectorService to accept DTOs

**Files:**
- Modify: `app/Services/ConnectorService.php`
- Modify: `app/Http/Controllers/Api/V1/Admin/ConnectorController.php`

**Step 1: Update ConnectorService**

```php
// create() — принимает CreateConnectorData вместо array
public function create(string $merchantKey, CreateConnectorData $dto): MerchantConnectorAccount
{
    $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);
    $profileId = $this->resolveProfileId($dto->profile_id);

    return $this->merchantRepository->createConnector([
        'merchant_account_id' => $merchant->id,
        'business_profile_id' => $profileId,
        ...$dto->except('profile_id')->toArray(),
    ]);
}

// update() — принимает UpdateConnectorData
public function update(string $merchantKey, string $connectorKey, UpdateConnectorData $dto): MerchantConnectorAccount
{
    $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);
    $connector = $this->merchantRepository->findConnectorByMerchantAndKey($merchant->id, $connectorKey);

    $updateData = $dto->toUpdateArray();

    // Resolve profile_id → business_profile_id
    if (isset($updateData['profile_id'])) {
        $updateData['business_profile_id'] = $this->resolveProfileId($updateData['profile_id']);
        unset($updateData['profile_id']);
    }

    $this->merchantRepository->updateConnector($connector, $updateData);
    return $connector->fresh();
}

// Вынести в приватный метод
private function resolveProfileId(?string $profileKey): ?int
{
    if (! $profileKey) {
        return null;
    }
    return $this->merchantRepository->findProfileByKey($profileKey)->id;
}
```

**Step 2: Update ConnectorController**

```php
// store: $request->toDto() вместо validatedAttributes()
$connector = $this->connectorService->create($merchantKey, $request->toDto());

// update: $request->toDto()
$connector = $this->connectorService->update($merchantKey, $connectorKey, $request->toDto());
```

**Step 3: Run tests, commit**

```
refactor: ConnectorService accepts CreateConnectorData/UpdateConnectorData DTOs
```

---

## Task 4: Refactor CustomerService to accept DTOs

**Files:**
- Modify: `app/Services/CustomerService.php`
- Modify: `app/Http/Controllers/Api/V1/CustomerController.php`

**Step 1: Update CustomerService**

```php
public function create(CreateCustomerData $dto, int|string $merchantAccountId, ?string $customId = null): Customer
{
    $attributes = [
        'merchant_account_id' => $merchantAccountId,
        ...$dto->toArray(),
    ];

    if ($customId !== null) {
        // Валидация customId остаётся в сервисе — это бизнес-правило (uniqueness check)
        if (strlen($customId) > 64 || strlen($customId) < 1) {
            throw new PaymentException('Customer ID must be 1-64 characters', 'invalid_customer_id', 'invalid_request_error', 400);
        }
        if ($this->customerRepository->existsByKey($customId, $merchantAccountId)) {
            throw new PaymentException('Customer ID already exists', 'duplicate_customer_id', 'invalid_request_error', 409);
        }
        $attributes['key'] = $customId;
    }

    return $this->customerRepository->create($attributes);
}

public function update(string $customerKey, UpdateCustomerData $dto, int|string $merchantAccountId): Customer
{
    $customer = $this->customerRepository->findByKey($customerKey, $merchantAccountId);
    $updateData = $dto->toUpdateArray();

    if (! empty($updateData)) {
        $this->customerRepository->update($customer, $updateData);
    }

    return $customer->fresh();
}
```

**Step 2: Update CustomerController** — `$request->toDto()` для store и update

**Step 3: Run tests, commit**

```
refactor: CustomerService accepts CreateCustomerData/UpdateCustomerData DTOs
```

---

## Task 5: Refactor PaymentMethodService to accept DTO

**Files:**
- Modify: `app/Services/PaymentMethodService.php`
- Modify: `app/Http/Controllers/Api/V1/PaymentMethodController.php`

**Step 1: Update PaymentMethodService::create()**

```php
public function create(CreatePaymentMethodData $dto, string $customerKey, int|string $merchantAccountId): PaymentMethod
{
    $customer = $this->customerRepository->findByKey($customerKey, $merchantAccountId);

    $last4 = $dto->card_last4;
    $brand = $dto->card_brand;

    if ($dto->card_number) {
        Log::warning('Full card number received — use client-side tokenization in production');
        $last4 = substr($dto->card_number, -4);
        $brand = self::detectBrand($dto->card_number);
    }

    $metadata = $dto->metadata ? array_slice($dto->metadata, 0, 50) : null;

    return $this->paymentMethodRepository->create([
        'customer_id' => $customer->id,
        'merchant_account_id' => $merchantAccountId,
        'type' => $dto->type,
        'card_last4' => $last4,
        'card_brand' => $brand,
        'card_exp_month' => $dto->card_exp_month,
        'card_exp_year' => $dto->card_exp_year,
        'card_holder_name' => $dto->card_holder_name,
        'connector_name' => $dto->connector_name,
        'connector_token' => $dto->connector_token ?? 'tok_' . bin2hex(random_bytes(16)),
        'is_default' => $dto->is_default,
        'metadata' => $metadata,
    ]);
}
```

**Step 2: Update controller, run tests, commit**

```
refactor: PaymentMethodService accepts CreatePaymentMethodData DTO
```

---

## Task 6: Refactor MerchantService to accept DTOs

**Files:**
- Modify: `app/Services/MerchantService.php`
- Modify: admin controllers (Organization, MerchantAccount, BusinessProfile, ApiKey)

**Step 1: Update methods to accept DTOs**

```php
public function createOrganization(CreateOrganizationData $dto): Organization
{
    return $this->merchantRepository->createOrganization($dto->toArray());
}

public function createMerchantAccount(CreateMerchantAccountData $dto): MerchantAccount
{
    $organization = $this->merchantRepository->findOrganizationByKey($dto->organization_id);
    return $this->merchantRepository->createMerchantAccount([
        'org_id' => $organization->id,
        'name' => $dto->name,
    ]);
}

public function createBusinessProfile(CreateBusinessProfileData $dto): BusinessProfile
{
    $merchant = $this->merchantRepository->findMerchantByKey($dto->merchant_id);
    return $this->merchantRepository->createBusinessProfile([
        'merchant_account_id' => $merchant->id,
        'webhook_url' => $dto->webhook_url,
    ]);
}

public function createApiKey(string $merchantKey, CreateApiKeyData $dto): array
{
    // ... принимает DTO вместо ?string $name
}
```

**Step 2: Fix `revokeApiKey` — убрать `$merchant->apiKeys()->findOrFail()` (прямой model access)**

Добавить `revokeApiKey(int $merchantAccountId, string $keyId): void` в `MerchantRepositoryInterface`.

**Step 3: Update all admin controllers → `$request->toDto()`, run tests, commit**

```
refactor: MerchantService accepts DTOs, remove direct model access from revokeApiKey
```

---

## Task 7: Refactor RoutingRuleController to accept DTOs

**Files:**
- Modify: `app/Http/Controllers/Api/V1/Admin/RoutingRuleController.php`

**Step 1:** Controller store → `$request->toDto()` вместо `validatedAttributes()`

**Step 2:** Controller update → `$request->toDto()`, использовать `toUpdateArray()`

**Step 3:** Run tests, commit

```
refactor: RoutingRuleController uses CreateRoutingRuleData/UpdateRoutingRuleData DTOs
```

---

## Task 8: Refactor PaymentService — inject RoutingService, accept DTOs

**Files:**
- Modify: `app/Services/PaymentService.php`

**Step 1: Inject RoutingService через конструктор** (сейчас `app(RoutingService::class)` — service locator anti-pattern)

```php
public function __construct(
    private PaymentIntentRepositoryInterface $paymentRepository,
    private MerchantRepositoryInterface $merchantRepository,
    private RoutingService $routingService,  // ← добавить
) {}
```

Убрать `$routingService = app(RoutingService::class);` из confirm().

**Step 2: create() принимает CreatePaymentData**

```php
public function create(CreatePaymentData $dto, int|string $merchantAccountId): PaymentIntent
{
    if ($dto->payment_id) {
        $existing = $this->paymentRepository->findByKeyOrNull($dto->payment_id, $merchantAccountId);
        if ($existing) {
            return $existing;
        }
    }

    $expiry = $dto->session_expiry ?? (int) config('payswitch.payment.session_expiry', 900);

    return $this->paymentRepository->create([
        'merchant_account_id' => $merchantAccountId,
        'amount' => $dto->amount,
        'currency' => strtoupper($dto->currency),
        'status' => PaymentStatus::RequiresPaymentMethod,
        'capture_method' => $dto->capture_method,
        'authentication_type' => $dto->authentication_type,
        'customer_id' => $dto->customer_id,
        'description' => $dto->description,
        'return_url' => $dto->return_url,
        'metadata' => $dto->metadata,
        'session_expiry' => $expiry,
        'attempt_count' => 1,
        'expires_on' => now()->addSeconds($expiry),
        'amount_capturable' => $dto->amount,
    ]);
}
```

Убрать дублирующую валидацию amount/currency/session_expiry — DTO + FormRequest уже валидируют.

**Step 3: confirm() принимает ConfirmPaymentData**

```php
public function confirm(string $paymentKey, ConfirmPaymentData $dto, int|string $merchantAccountId): PaymentIntent
```

Заменить `$data['connector'] ?? null` на `$dto->connector`, `$data['payment_method']` на `$dto->payment_method` и т.д.

**Step 4: Update PaymentController** — `$request->toDto()` для store, confirm, capture

**Step 5: Run tests, commit**

```
refactor: PaymentService accepts DTOs, inject RoutingService via constructor
```

---

## Task 9: Refactor RefundService to accept DTO

**Files:**
- Modify: `app/Services/RefundService.php`
- Modify: `app/Http/Controllers/Api/V1/RefundController.php`

**Step 1: create() принимает CreateRefundData**

```php
public function create(CreateRefundData $dto, int|string $merchantAccountId): Refund
{
    return DB::transaction(function () use ($dto, $merchantAccountId) {
        $payment = $this->paymentRepository->findByKeyLocked($dto->payment_id, $merchantAccountId);
        // ... остальная логика с $dto->amount, $dto->reason, $dto->metadata
    });
}
```

Убрать ручные проверки `isset($data['payment_id'])`, `isset($data['amount'])` — DTO гарантирует наличие.

**Step 2: Update RefundController, run tests, commit**

```
refactor: RefundService accepts CreateRefundData DTO
```

---

## Task 10: Make services `final readonly` + lint + final tests

**Files:**
- Modify: all 7 services — `final class` → `final readonly class` (где возможно — зависит от mutable state)

**Note:** `readonly` class требует чтобы ВСЕ свойства были `readonly`. Наши сервисы уже injектят через `private` constructor promotion — нужно добавить `readonly` к классу.

**Проверить:** `PaymentMethodService::detectBrand()` — статический метод, не мешает readonly.

**Step 1:** Обновить все сервисы на `final readonly class`

**Step 2:** Run `composer lint` — Pint

**Step 3:** Run `./vendor/bin/pest` — все тесты зелёные

**Step 4:** Commit

```
refactor: make all services final readonly, apply Pint formatting
```

---

## Summary: Task Dependencies

```
Task 1 (create DTOs)
  → Task 2 (add toDto to FormRequests) — depends on 1
  → Task 3 (ConnectorService) — depends on 1, 2
  → Task 4 (CustomerService) — depends on 2
  → Task 5 (PaymentMethodService) — depends on 1, 2
  → Task 6 (MerchantService) — depends on 2
  → Task 7 (RoutingRuleController) — depends on 1, 2
  → Task 8 (PaymentService) — depends on 2
  → Task 9 (RefundService) — depends on 2
  → Task 10 (final readonly + lint) — depends on all above
```

Tasks 3-9 можно выполнять параллельно после Tasks 1-2.
Task 10 — финальный.
