# Payswitch Architecture Refactor — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Refactor the Payswitch API codebase to fully comply with the laravel-api architecture skill — proper layer separation (Controller → Service → Repository → QueryBuilder), JSON:API Resources, DTO integration, Enums for all magic strings.

**Architecture:** Replace the custom `JsonApiResponse` trait with `timacdonald/json-api` Resource classes. Wire DTOs from FormRequest → Controller → Service. Move all DB access out of Services into Repositories. Connect QueryBuilders to Repositories. Extract business logic from fat controllers (WebhookReceiver, PaymentMethod, Admin) into dedicated Services.

**Tech Stack:** Laravel 13, PHP 8.3+, `timacdonald/json-api`, `spatie/laravel-data`, `spatie/laravel-query-builder`, Pest PHP

---

## Critical Context

- Models live in external package `Streeboga\PaymentData\Models\*` — do NOT create app-level models
- Enums (PaymentStatus, CaptureMethod, RefundStatus, AuthenticationType) live in `Streeboga\PaymentData\Enums\*`
- StateMachine lives in `Streeboga\PaymentData\StateMachine\PaymentStateMachine`
- Exceptions (PaymentException, ConnectorException) live in `Streeboga\PaymentData\Exceptions\*`
- `spatie/laravel-query-builder` v6.4.4 is installed
- `timacdonald/json-api` is NOT installed — must add in Task 1
- Existing tests in `tests/Feature/Api/` — must stay green after each task

---

## Task 1: Install timacdonald/json-api + Create Base Resource

**Files:**
- Modify: `composer.json`
- Create: `app/Http/Resources/BaseResource.php`

**Step 1: Install package**

Run: `composer require timacdonald/json-api`
Expected: Package installs successfully

**Step 2: Create base resource class**

```php
<?php
// app/Http/Resources/BaseResource.php

declare(strict_types=1);

namespace App\Http\Resources;

use TiMacDonald\JsonApi\JsonApiResource;
use TiMacDonald\JsonApi\Link;

abstract class BaseResource extends JsonApiResource
{
    public function toId($request): string
    {
        return $this->key ?? (string) $this->id;
    }

    abstract public function toType($request): string;

    public function toLinks($request): array
    {
        return [
            Link::self(request()->url()),
        ];
    }
}
```

**Step 3: Run tests to verify nothing broke**

Run: `./vendor/bin/pest`
Expected: All existing tests pass

**Step 4: Commit**

```bash
git add composer.json composer.lock app/Http/Resources/BaseResource.php
git commit -m "feat: install timacdonald/json-api and create BaseResource"
```

---

## Task 2: Create JsonApiResource classes for all entities

**Files:**
- Create: `app/Http/Resources/PaymentIntentResource.php`
- Create: `app/Http/Resources/RefundResource.php`
- Create: `app/Http/Resources/CustomerResource.php`
- Create: `app/Http/Resources/PaymentMethodResource.php`
- Create: `app/Http/Resources/OrganizationResource.php`
- Create: `app/Http/Resources/MerchantAccountResource.php`
- Create: `app/Http/Resources/BusinessProfileResource.php`
- Create: `app/Http/Resources/ApiKeyResource.php`
- Create: `app/Http/Resources/ConnectorResource.php`
- Create: `app/Http/Resources/RoutingRuleResource.php`

**Step 1: Create PaymentIntentResource**

```php
<?php
// app/Http/Resources/PaymentIntentResource.php

declare(strict_types=1);

namespace App\Http\Resources;

use TiMacDonald\JsonApi\Link;

final class PaymentIntentResource extends BaseResource
{
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
            'capture_method' => $this->capture_method instanceof \BackedEnum ? $this->capture_method->value : $this->capture_method,
            'authentication_type' => $this->authentication_type instanceof \BackedEnum ? $this->authentication_type->value : $this->authentication_type,
            'customer_id' => $this->customer_id,
            'description' => $this->description,
            'return_url' => $this->return_url,
            'metadata' => $this->metadata,
            'connector' => $this->connector,
            'attempt_count' => $this->attempt_count,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'cancellation_reason' => $this->cancellation_reason,
            'session_expiry' => $this->session_expiry,
            'created_at' => $this->created_at->toIso8601String(),
            'expires_on' => $this->expires_on?->toIso8601String(),
        ];
    }

    public function toLinks($request): array
    {
        return [
            Link::self("/api/v1/payments/{$this->key}"),
        ];
    }
}
```

**Step 2: Create RefundResource**

```php
<?php
// app/Http/Resources/RefundResource.php

declare(strict_types=1);

namespace App\Http\Resources;

use TiMacDonald\JsonApi\Link;

final class RefundResource extends BaseResource
{
    public function toType($request): string
    {
        return 'refunds';
    }

    public function toAttributes($request): array
    {
        return [
            'payment_id' => $this->paymentIntent?->key,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status->value,
            'reason' => $this->reason,
            'connector' => $this->connector,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    public function toRelationships($request): array
    {
        return [
            'payment' => fn () => PaymentIntentResource::make($this->paymentIntent),
        ];
    }

    public function toLinks($request): array
    {
        return [
            Link::self("/api/v1/refunds/{$this->key}"),
        ];
    }
}
```

**Step 3: Create CustomerResource**

```php
<?php
// app/Http/Resources/CustomerResource.php

declare(strict_types=1);

namespace App\Http\Resources;

use TiMacDonald\JsonApi\Link;

final class CustomerResource extends BaseResource
{
    public function toType($request): string
    {
        return 'customers';
    }

    public function toAttributes($request): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'phone_country_code' => $this->phone_country_code,
            'description' => $this->description,
            'metadata' => $this->metadata,
            'default_payment_method_id' => $this->default_payment_method_id,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    public function toLinks($request): array
    {
        return [
            Link::self("/api/v1/customers/{$this->key}"),
        ];
    }
}
```

**Step 4: Create PaymentMethodResource**

```php
<?php
// app/Http/Resources/PaymentMethodResource.php

declare(strict_types=1);

namespace App\Http\Resources;

use TiMacDonald\JsonApi\Link;

final class PaymentMethodResource extends BaseResource
{
    public function toType($request): string
    {
        return 'payment-methods';
    }

    public function toAttributes($request): array
    {
        return [
            'type' => $this->type,
            'card_last4' => $this->card_last4,
            'card_brand' => $this->card_brand,
            'card_exp_month' => $this->card_exp_month,
            'card_exp_year' => $this->card_exp_year,
            'card_holder_name' => $this->card_holder_name,
            'connector_name' => $this->connector_name,
            'is_default' => $this->is_default,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    public function toLinks($request): array
    {
        return [
            Link::self("/api/v1/payment-methods/{$this->key}"),
        ];
    }
}
```

**Step 5: Create admin resources (Organization, Merchant, Profile, ApiKey, Connector, RoutingRule)**

```php
<?php
// app/Http/Resources/OrganizationResource.php

declare(strict_types=1);

namespace App\Http\Resources;

use TiMacDonald\JsonApi\Link;

final class OrganizationResource extends BaseResource
{
    public function toType($request): string
    {
        return 'organizations';
    }

    public function toAttributes($request): array
    {
        return [
            'name' => $this->name,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    public function toLinks($request): array
    {
        return [
            Link::self("/api/v1/organizations/{$this->key}"),
        ];
    }
}
```

```php
<?php
// app/Http/Resources/MerchantAccountResource.php

declare(strict_types=1);

namespace App\Http\Resources;

use TiMacDonald\JsonApi\Link;

final class MerchantAccountResource extends BaseResource
{
    public function toType($request): string
    {
        return 'merchants';
    }

    public function toAttributes($request): array
    {
        return [
            'name' => $this->name,
            'publishable_key' => $this->publishable_key,
            'organization_id' => $this->organization?->key,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    public function toRelationships($request): array
    {
        return [
            'organization' => fn () => OrganizationResource::make($this->organization),
        ];
    }

    public function toLinks($request): array
    {
        return [
            Link::self("/api/v1/merchants/{$this->key}"),
        ];
    }
}
```

```php
<?php
// app/Http/Resources/BusinessProfileResource.php

declare(strict_types=1);

namespace App\Http\Resources;

use TiMacDonald\JsonApi\Link;

final class BusinessProfileResource extends BaseResource
{
    public function toType($request): string
    {
        return 'profiles';
    }

    public function toAttributes($request): array
    {
        return [
            'merchant_id' => $this->merchantAccount?->key,
            'webhook_url' => $this->webhook_url,
            'payment_response_hash_key' => $this->payment_response_hash_key,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    public function toLinks($request): array
    {
        return [
            Link::self("/api/v1/profiles/{$this->key}"),
        ];
    }
}
```

```php
<?php
// app/Http/Resources/ApiKeyResource.php

declare(strict_types=1);

namespace App\Http\Resources;

final class ApiKeyResource extends BaseResource
{
    public function toType($request): string
    {
        return 'api-keys';
    }

    public function toAttributes($request): array
    {
        $attrs = [
            'name' => $this->name,
            'key_prefix' => $this->key_prefix,
            'created_at' => $this->created_at->toIso8601String(),
        ];

        // Include plain API key only on creation (passed via additional)
        if ($this->additional['api_key'] ?? null) {
            $attrs['api_key'] = $this->additional['api_key'];
        }

        return $attrs;
    }
}
```

```php
<?php
// app/Http/Resources/ConnectorResource.php

declare(strict_types=1);

namespace App\Http\Resources;

use TiMacDonald\JsonApi\Link;

final class ConnectorResource extends BaseResource
{
    public function toType($request): string
    {
        return 'connectors';
    }

    public function toAttributes($request): array
    {
        return [
            'connector_name' => $this->connector_name,
            'connector_type' => $this->connector_type,
            'payment_methods_enabled' => $this->payment_methods_enabled,
            'test_mode' => $this->test_mode,
            'disabled' => $this->disabled,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    public function toLinks($request): array
    {
        return [
            Link::self("/api/v1/merchants/{$this->merchantAccount?->key}/connectors/{$this->key}"),
        ];
    }
}
```

```php
<?php
// app/Http/Resources/RoutingRuleResource.php

declare(strict_types=1);

namespace App\Http\Resources;

final class RoutingRuleResource extends BaseResource
{
    public function toType($request): string
    {
        return 'routing-rules';
    }

    public function toAttributes($request): array
    {
        return [
            'type' => $this->type,
            'name' => $this->name,
            'rules' => $this->rules,
            'active' => $this->active,
            'priority' => $this->priority,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
```

**Step 6: Run tests**

Run: `./vendor/bin/pest`
Expected: All pass (resources not wired yet)

**Step 7: Commit**

```bash
git add app/Http/Resources/
git commit -m "feat: create JSON:API Resource classes for all entities"
```

---

## Task 3: Create local Enums for magic strings

**Files:**
- Create: `app/Enums/RoutingRuleType.php`
- Create: `app/Enums/ConnectorName.php`
- Create: `app/Enums/PaymentAttemptStatus.php`
- Create: `app/Enums/WebhookEventType.php`

**Step 1: Create RoutingRuleType enum**

```php
<?php
// app/Enums/RoutingRuleType.php

declare(strict_types=1);

namespace App\Enums;

enum RoutingRuleType: string
{
    case Priority = 'priority';
    case RuleBased = 'rule_based';
    case VolumeSplit = 'volume_split';
}
```

**Step 2: Create ConnectorName enum**

```php
<?php
// app/Enums/ConnectorName.php

declare(strict_types=1);

namespace App\Enums;

enum ConnectorName: string
{
    case Stripe = 'stripe';
    case CloudPayments = 'cloudpayments';
    case Test = 'test';
}
```

**Step 3: Create PaymentAttemptStatus enum**

```php
<?php
// app/Enums/PaymentAttemptStatus.php

declare(strict_types=1);

namespace App\Enums;

enum PaymentAttemptStatus: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
```

**Step 4: Create WebhookEventType enum**

```php
<?php
// app/Enums/WebhookEventType.php

declare(strict_types=1);

namespace App\Enums;

enum WebhookEventType: string
{
    case PaymentSucceeded = 'payment_succeeded';
    case PaymentCaptured = 'payment_captured';
    case PaymentCancelled = 'payment_cancelled';
    case PaymentAuthorized = 'payment_authorized';
    case PaymentStatusChanged = 'payment_status_changed';
    case RefundSucceeded = 'refund_succeeded';
    case RefundFailed = 'refund_failed';
}
```

**Step 5: Run tests**

Run: `./vendor/bin/pest`
Expected: All pass (enums not wired yet)

**Step 6: Commit**

```bash
git add app/Enums/
git commit -m "feat: create local Enums for routing rule types, connectors, attempts, webhooks"
```

---

## Task 4: Create missing FormRequests

**Files:**
- Create: `app/Http/Requests/Api/PaymentMethod/StorePaymentMethodRequest.php`
- Create: `app/Http/Requests/Api/Admin/StoreRoutingRuleRequest.php`
- Create: `app/Http/Requests/Api/Admin/UpdateRoutingRuleRequest.php`
- Create: `app/Http/Requests/Api/Admin/UpdateConnectorRequest.php`

**Step 1: Create StorePaymentMethodRequest**

```php
<?php
// app/Http/Requests/Api/PaymentMethod/StorePaymentMethodRequest.php

declare(strict_types=1);

namespace App\Http\Requests\Api\PaymentMethod;

use Illuminate\Foundation\Http\FormRequest;

final class StorePaymentMethodRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'data.attributes.type' => ['required', 'string', 'in:card,bank_account'],
            'data.attributes.connector_name' => ['required', 'string'],
            'data.attributes.card_last4' => ['sometimes', 'string', 'size:4'],
            'data.attributes.card_brand' => ['sometimes', 'string'],
            'data.attributes.card_exp_month' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'data.attributes.card_exp_year' => ['sometimes', 'integer', 'min:2024'],
            'data.attributes.card_holder_name' => ['sometimes', 'string', 'max:255'],
            'data.attributes.connector_token' => ['sometimes', 'string'],
            'data.attributes.is_default' => ['sometimes', 'boolean'],
            'data.attributes.metadata' => ['sometimes', 'nullable', 'array', 'max:50'],
        ];
    }
}
```

**Step 2: Create StoreRoutingRuleRequest**

```php
<?php
// app/Http/Requests/Api/Admin/StoreRoutingRuleRequest.php

declare(strict_types=1);

namespace App\Http\Requests\Api\Admin;

use App\Enums\RoutingRuleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreRoutingRuleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'data.attributes.type' => ['required', 'string', Rule::enum(RoutingRuleType::class)],
            'data.attributes.name' => ['required', 'string', 'max:255'],
            'data.attributes.rules' => ['required', 'array'],
            'data.attributes.active' => ['sometimes', 'boolean'],
            'data.attributes.priority' => ['sometimes', 'integer', 'min:0'],
            'data.attributes.business_profile_id' => ['sometimes', 'string'],
        ];
    }

    public function validatedAttributes(): array
    {
        return $this->validated('data.attributes');
    }
}
```

**Step 3: Create UpdateRoutingRuleRequest**

```php
<?php
// app/Http/Requests/Api/Admin/UpdateRoutingRuleRequest.php

declare(strict_types=1);

namespace App\Http\Requests\Api\Admin;

use App\Enums\RoutingRuleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateRoutingRuleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'data.attributes.type' => ['sometimes', 'string', Rule::enum(RoutingRuleType::class)],
            'data.attributes.name' => ['sometimes', 'string', 'max:255'],
            'data.attributes.rules' => ['sometimes', 'array'],
            'data.attributes.active' => ['sometimes', 'boolean'],
            'data.attributes.priority' => ['sometimes', 'integer', 'min:0'],
            'data.attributes.business_profile_id' => ['sometimes', 'string'],
        ];
    }

    public function validatedAttributes(): array
    {
        return $this->validated('data.attributes');
    }
}
```

**Step 4: Create UpdateConnectorRequest**

```php
<?php
// app/Http/Requests/Api/Admin/UpdateConnectorRequest.php

declare(strict_types=1);

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateConnectorRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'data.attributes.connector_name' => ['sometimes', 'string'],
            'data.attributes.disabled' => ['sometimes', 'boolean'],
            'data.attributes.test_mode' => ['sometimes', 'boolean'],
            'data.attributes.connector_account_details' => ['sometimes', 'array'],
            'data.attributes.payment_methods_enabled' => ['sometimes', 'array'],
            'data.attributes.profile_id' => ['sometimes', 'string'],
        ];
    }

    public function validatedAttributes(): array
    {
        return $this->validated('data.attributes');
    }
}
```

**Step 5: Run tests**

Run: `./vendor/bin/pest`
Expected: All pass

**Step 6: Commit**

```bash
git add app/Http/Requests/Api/PaymentMethod/ app/Http/Requests/Api/Admin/StoreRoutingRuleRequest.php app/Http/Requests/Api/Admin/UpdateRoutingRuleRequest.php app/Http/Requests/Api/Admin/UpdateConnectorRequest.php
git commit -m "feat: create missing FormRequests for PaymentMethod, RoutingRule, Connector updates"
```

---

## Task 5: Create missing Services (WebhookReceiver, PaymentMethod, Merchant, Connector)

**Files:**
- Create: `app/Services/WebhookReceiverService.php`
- Create: `app/Services/PaymentMethodService.php`
- Create: `app/Services/MerchantService.php`
- Create: `app/Services/ConnectorService.php`

**Step 1: Create WebhookReceiverService**

Extract ALL logic from `WebhookReceiverController` into this service. The service must:
- Accept merchant key and MCA key, resolve via repository
- Verify webhook signature (Stripe, CloudPayments, test)
- Process webhook: find payment, map PSP event to status, update via repository
- Fire PaymentStatusChanged event
- Return a result DTO or throw on invalid signature

```php
<?php
// app/Services/WebhookReceiverService.php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ConnectorName;
use App\Events\PaymentStatusChanged;
use App\Repositories\Contracts\MerchantRepositoryInterface;
use App\Repositories\Contracts\PaymentIntentRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\StateMachine\PaymentStateMachine;

final class WebhookReceiverService
{
    public function __construct(
        private MerchantRepositoryInterface $merchantRepository,
        private PaymentIntentRepositoryInterface $paymentRepository,
    ) {}

    /**
     * @return array{status: string, code: int}
     */
    public function handle(Request $request, string $merchantKey, string $mcaKey): array
    {
        $merchant = $this->merchantRepository->findMerchantByKeyOrNull($merchantKey);
        if (! $merchant) {
            return ['status' => 'ignored', 'code' => 404];
        }

        $mca = $this->merchantRepository->findConnectorByMerchantAndKeyOrNull($merchant->id, $mcaKey);
        if (! $mca) {
            return ['status' => 'ignored', 'code' => 404];
        }

        if (! $this->verifySignature($request, $mca)) {
            Log::warning('Webhook signature verification failed', [
                'merchant_key' => $merchantKey,
                'mca_key' => $mcaKey,
                'connector' => $mca->connector_name,
            ]);
            return ['status' => 'invalid_signature', 'code' => 401];
        }

        $payload = $request->all();

        Log::info("Incoming webhook from {$mca->connector_name}", [
            'mca_key' => $mcaKey,
            'payload_type' => $payload['type'] ?? 'unknown',
        ]);

        try {
            $this->processWebhook($mca, $payload);
        } catch (\Exception $e) {
            Log::error('Webhook processing failed', [
                'mca_key' => $mcaKey,
                'error' => $e->getMessage(),
            ]);
        }

        return ['status' => 'ok', 'code' => 200];
    }

    private function verifySignature(Request $request, MerchantConnectorAccount $mca): bool
    {
        $connector = $mca->connector_name;

        if ($connector === ConnectorName::Test->value) {
            return true;
        }

        if ($connector === ConnectorName::Stripe->value) {
            $signature = $request->header('Stripe-Signature');
            if (! $signature) {
                return false;
            }
            $credentials = $mca->connector_account_details;
            $webhookSecret = $credentials['webhook_secret'] ?? null;
            if (! $webhookSecret) {
                Log::warning("Stripe webhook secret not configured for MCA {$mca->key}");
                return false;
            }
            return $this->verifyStripeSignature($request->getContent(), $signature, $webhookSecret);
        }

        if ($connector === ConnectorName::CloudPayments->value) {
            Log::warning("CloudPayments webhook signature verification not implemented for MCA {$mca->key}");
            return ! app()->environment('production');
        }

        Log::warning("Unknown connector for webhook verification: {$connector}");
        return false;
    }

    private function verifyStripeSignature(string $payload, string $signatureHeader, string $secret): bool
    {
        $elements = explode(',', $signatureHeader);
        $timestamp = null;
        $signatures = [];

        foreach ($elements as $element) {
            if (! str_contains($element, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $element, 2);
            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if (! $timestamp || empty($signatures)) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $signedPayload = $timestamp . '.' . $payload;
        $expectedSignature = hash_hmac('sha256', $signedPayload, $secret);

        foreach ($signatures as $sig) {
            if (hash_equals($expectedSignature, $sig)) {
                return true;
            }
        }

        return false;
    }

    private function processWebhook(MerchantConnectorAccount $mca, array $payload): void
    {
        $paymentId = $payload['data']['object']['metadata']['payment_id'] ?? null;
        if (! $paymentId) {
            return;
        }

        $payment = $this->paymentRepository->findByKeyOrNull($paymentId, $mca->merchant_account_id);
        if (! $payment) {
            return;
        }

        $newStatus = $this->mapPspEventToStatus($mca->connector_name, $payload['type'] ?? '');
        if (! $newStatus || ! PaymentStateMachine::canTransition($payment->status, $newStatus)) {
            return;
        }

        $result = DB::transaction(function () use ($payment, $newStatus) {
            $lockedPayment = $this->paymentRepository->findByIdLocked($payment->id);
            if (! $lockedPayment) {
                return null;
            }
            $previousStatus = $lockedPayment->status->value;

            if (PaymentStateMachine::canTransition($lockedPayment->status, $newStatus)) {
                $updateData = ['status' => $newStatus];
                if ($newStatus === PaymentStatus::Succeeded) {
                    $updateData['amount_received'] = $lockedPayment->amount;
                }
                $this->paymentRepository->update($lockedPayment, $updateData);
                return ['payment' => $lockedPayment->fresh(), 'previousStatus' => $previousStatus];
            }

            return null;
        });

        if ($result) {
            event(new PaymentStatusChanged($result['payment'], $result['previousStatus']));
        }
    }

    private function mapPspEventToStatus(string $connector, string $eventType): ?PaymentStatus
    {
        if ($connector === ConnectorName::Stripe->value) {
            return match ($eventType) {
                'payment_intent.succeeded' => PaymentStatus::Succeeded,
                'payment_intent.payment_failed' => PaymentStatus::Failed,
                'payment_intent.canceled' => PaymentStatus::Cancelled,
                'payment_intent.requires_action' => PaymentStatus::RequiresCustomerAction,
                default => null,
            };
        }

        if ($connector === ConnectorName::CloudPayments->value) {
            return match ($eventType) {
                'payment.succeeded' => PaymentStatus::Succeeded,
                'payment.canceled' => PaymentStatus::Cancelled,
                'payment.waiting_for_capture' => PaymentStatus::RequiresCapture,
                default => null,
            };
        }

        return null;
    }
}
```

**NOTE:** This requires adding `findMerchantByKeyOrNull`, `findConnectorByMerchantAndKeyOrNull`, `findByIdLocked` to repository interfaces. These will be added in Task 7.

**Step 2: Create PaymentMethodService**

```php
<?php
// app/Services/PaymentMethodService.php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Repositories\Contracts\PaymentMethodRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Streeboga\PaymentData\Models\PaymentMethod;

final class PaymentMethodService
{
    public function __construct(
        private CustomerRepositoryInterface $customerRepository,
        private PaymentMethodRepositoryInterface $paymentMethodRepository,
    ) {}

    public function create(array $attributes, string $customerKey, int|string $merchantAccountId): PaymentMethod
    {
        $customer = $this->customerRepository->findByKey($customerKey, $merchantAccountId);

        if (isset($attributes['card_number'])) {
            Log::warning('Full card number received — should use client-side tokenization in production');
            $cardNumber = $attributes['card_number'];
            $last4 = substr($cardNumber, -4);
            $brand = self::detectBrand($cardNumber);
        } else {
            $last4 = $attributes['card_last4'] ?? null;
            $brand = $attributes['card_brand'] ?? null;
        }

        $metadata = isset($attributes['metadata']) && is_array($attributes['metadata'])
            ? array_slice($attributes['metadata'], 0, 50)
            : null;

        return $this->paymentMethodRepository->create([
            'customer_id' => $customer->id,
            'merchant_account_id' => $merchantAccountId,
            'type' => $attributes['type'] ?? 'card',
            'card_last4' => $last4,
            'card_brand' => $brand,
            'card_exp_month' => $attributes['card_exp_month'] ?? null,
            'card_exp_year' => $attributes['card_exp_year'] ?? null,
            'card_holder_name' => $attributes['card_holder_name'] ?? null,
            'connector_name' => $attributes['connector_name'],
            'connector_token' => $attributes['connector_token'] ?? 'tok_' . bin2hex(random_bytes(16)),
            'is_default' => $attributes['is_default'] ?? false,
            'metadata' => $metadata,
        ]);
    }

    public function listForCustomer(string $customerKey, int|string $merchantAccountId): Collection
    {
        $customer = $this->customerRepository->findByKey($customerKey, $merchantAccountId);

        return $this->paymentMethodRepository->findByCustomer($customer->id, $merchantAccountId);
    }

    public function find(string $pmKey, int|string $merchantAccountId): PaymentMethod
    {
        return $this->paymentMethodRepository->findByKey($pmKey, $merchantAccountId);
    }

    public function delete(string $pmKey, int|string $merchantAccountId): void
    {
        $pm = $this->paymentMethodRepository->findByKey($pmKey, $merchantAccountId);
        $this->paymentMethodRepository->delete($pm);
    }

    public function setDefault(string $pmKey, int|string $merchantAccountId): PaymentMethod
    {
        $pm = $this->paymentMethodRepository->findByKey($pmKey, $merchantAccountId);

        DB::transaction(function () use ($pm, $merchantAccountId) {
            $this->paymentMethodRepository->unsetDefaultForCustomer($pm->customer_id, $merchantAccountId, $pm->id);
            $this->paymentMethodRepository->update($pm, ['is_default' => true]);
        });

        return $pm->fresh();
    }

    public static function detectBrand(string $cardNumber): string
    {
        $number = preg_replace('/\D/', '', $cardNumber);

        return match (true) {
            str_starts_with($number, '4') => 'visa',
            str_starts_with($number, '5') && in_array($number[1] ?? '', ['1', '2', '3', '4', '5']) => 'mastercard',
            str_starts_with($number, '2') && isset($number[3]) && (int) substr($number, 0, 4) >= 2221 && (int) substr($number, 0, 4) <= 2720 => 'mastercard',
            str_starts_with($number, '220') => 'mir',
            str_starts_with($number, '34') || str_starts_with($number, '37') => 'amex',
            default => 'unknown',
        };
    }
}
```

**Step 3: Create MerchantService** (for organization, merchant account, business profile, API key operations)

```php
<?php
// app/Services/MerchantService.php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\Contracts\MerchantRepositoryInterface;
use Streeboga\PaymentData\Models\ApiKey;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Support\IdGenerator;

final class MerchantService
{
    public function __construct(
        private MerchantRepositoryInterface $merchantRepository,
    ) {}

    public function createOrganization(array $data): Organization
    {
        return $this->merchantRepository->createOrganization([
            'name' => $data['name'],
        ]);
    }

    public function findMerchant(string $merchantKey): MerchantAccount
    {
        return $this->merchantRepository->findMerchantByKey($merchantKey);
    }

    public function createMerchantAccount(array $data): MerchantAccount
    {
        $organization = $this->merchantRepository->findOrganizationByKey($data['organization_id']);

        return $this->merchantRepository->createMerchantAccount([
            'org_id' => $organization->id,
            'name' => $data['name'],
        ]);
    }

    public function findProfile(string $profileKey): BusinessProfile
    {
        return $this->merchantRepository->findProfileByKey($profileKey);
    }

    public function createBusinessProfile(array $data): BusinessProfile
    {
        $merchant = $this->merchantRepository->findMerchantByKey($data['merchant_id']);

        return $this->merchantRepository->createBusinessProfile([
            'merchant_account_id' => $merchant->id,
            'webhook_url' => $data['webhook_url'] ?? null,
        ]);
    }

    public function createApiKey(string $merchantKey, ?string $name = null): array
    {
        $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);

        $rawKey = IdGenerator::apiKey(config('payswitch.environment', 'sandbox'));

        $apiKey = $this->merchantRepository->createApiKey([
            'merchant_account_id' => $merchant->id,
            'key_hash' => hash('sha256', $rawKey),
            'key_prefix' => substr($rawKey, 0, 20),
            'name' => $name,
        ]);

        return ['apiKey' => $apiKey, 'rawKey' => $rawKey];
    }

    public function revokeApiKey(string $merchantKey, string $keyId): void
    {
        $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);
        $apiKey = $merchant->apiKeys()->findOrFail($keyId);
        $apiKey->revoke();
    }
}
```

**Step 4: Create ConnectorService**

```php
<?php
// app/Services/ConnectorService.php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\Contracts\MerchantRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;

final class ConnectorService
{
    public function __construct(
        private MerchantRepositoryInterface $merchantRepository,
    ) {}

    public function create(string $merchantKey, array $data): MerchantConnectorAccount
    {
        $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);

        $profileId = null;
        if (! empty($data['profile_id'])) {
            $profile = $this->merchantRepository->findProfileByKey($data['profile_id']);
            $profileId = $profile->id;
        }

        return $this->merchantRepository->createConnector([
            'merchant_account_id' => $merchant->id,
            'business_profile_id' => $profileId,
            'connector_name' => $data['connector_name'],
            'connector_type' => $data['connector_type'],
            'connector_account_details' => $data['connector_account_details'],
            'payment_methods_enabled' => $data['payment_methods_enabled'] ?? null,
            'test_mode' => $data['test_mode'] ?? false,
        ]);
    }

    public function list(string $merchantKey): Collection
    {
        $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);
        return $this->merchantRepository->getConnectorsByMerchant($merchant->id);
    }

    public function find(string $merchantKey, string $connectorKey): MerchantConnectorAccount
    {
        $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);
        return $this->merchantRepository->findConnectorByMerchantAndKey($merchant->id, $connectorKey);
    }

    public function update(string $merchantKey, string $connectorKey, array $data): MerchantConnectorAccount
    {
        $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);
        $connector = $this->merchantRepository->findConnectorByMerchantAndKey($merchant->id, $connectorKey);

        $updateData = collect($data)->only([
            'connector_name', 'connector_type', 'payment_methods_enabled',
            'test_mode', 'disabled',
        ])->toArray();

        if (isset($data['connector_account_details'])) {
            $updateData['connector_account_details'] = $data['connector_account_details'];
        }

        if (isset($data['profile_id'])) {
            $profile = $this->merchantRepository->findProfileByKey($data['profile_id']);
            $updateData['business_profile_id'] = $profile->id;
        }

        $this->merchantRepository->updateConnector($connector, $updateData);
        return $connector->fresh();
    }

    public function delete(string $merchantKey, string $connectorKey): void
    {
        $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);
        $connector = $this->merchantRepository->findConnectorByMerchantAndKey($merchant->id, $connectorKey);
        $this->merchantRepository->deleteConnector($connector);
    }
}
```

**Step 5: Run tests**

Run: `./vendor/bin/pest`
Expected: All pass (services not wired yet)

**Step 6: Commit**

```bash
git add app/Services/WebhookReceiverService.php app/Services/PaymentMethodService.php app/Services/MerchantService.php app/Services/ConnectorService.php
git commit -m "feat: create missing service classes for WebhookReceiver, PaymentMethod, Merchant, Connector"
```

---

## Task 6: Extend repository interfaces and implementations

**Files:**
- Modify: `app/Repositories/Contracts/MerchantRepositoryInterface.php`
- Modify: `app/Repositories/Contracts/PaymentIntentRepositoryInterface.php`
- Modify: `app/Repositories/Eloquent/MerchantRepository.php`
- Modify: `app/Repositories/Eloquent/PaymentIntentRepository.php`

**Step 1: Add missing methods to MerchantRepositoryInterface**

Add to interface:
```php
public function findMerchantByKeyOrNull(string $key): ?MerchantAccount;
public function findConnectorByMerchantAndKeyOrNull(int|string $merchantAccountId, string $connectorKey): ?MerchantConnectorAccount;
```

**Step 2: Add missing methods to MerchantRepository**

```php
public function findMerchantByKeyOrNull(string $key): ?MerchantAccount
{
    return MerchantAccount::where('key', $key)->first();
}

public function findConnectorByMerchantAndKeyOrNull(int|string $merchantAccountId, string $connectorKey): ?MerchantConnectorAccount
{
    return MerchantConnectorAccount::where('merchant_account_id', $merchantAccountId)
        ->where('key', $connectorKey)
        ->first();
}
```

**Step 3: Add findByIdLocked to PaymentIntentRepositoryInterface**

```php
public function findByIdLocked(int $id): ?PaymentIntent;
```

**Step 4: Implement in PaymentIntentRepository**

```php
public function findByIdLocked(int $id): ?PaymentIntent
{
    return PaymentIntent::where('id', $id)->lockForUpdate()->first();
}
```

**Step 5: Run tests**

Run: `./vendor/bin/pest`
Expected: All pass

**Step 6: Commit**

```bash
git add app/Repositories/
git commit -m "feat: extend repository interfaces with nullable finders and locked ID lookup"
```

---

## Task 7: Wire controllers to use Resources + Services + DTOs

This is the largest task — refactor ALL controllers to:
1. Use `$request->toDto()` or `$request->validatedAttributes()` (not raw `$request->input()`)
2. Delegate to service (not repository directly)
3. Return `JsonApiResource::make()` / `::collection()` (not `jsonApiResource()` trait)
4. Remove `JsonApiResponse` trait usage

**Files:**
- Modify: `app/Http/Controllers/Api/V1/PaymentController.php`
- Modify: `app/Http/Controllers/Api/V1/RefundController.php`
- Modify: `app/Http/Controllers/Api/V1/CustomerController.php`
- Modify: `app/Http/Controllers/Api/V1/PaymentMethodController.php`
- Modify: `app/Http/Controllers/Api/V1/WebhookReceiverController.php`
- Modify: `app/Http/Controllers/Api/V1/Admin/OrganizationController.php`
- Modify: `app/Http/Controllers/Api/V1/Admin/MerchantAccountController.php`
- Modify: `app/Http/Controllers/Api/V1/Admin/BusinessProfileController.php`
- Modify: `app/Http/Controllers/Api/V1/Admin/ApiKeyController.php`
- Modify: `app/Http/Controllers/Api/V1/Admin/ConnectorController.php`
- Modify: `app/Http/Controllers/Api/V1/Admin/RoutingRuleController.php`

**Approach:** Refactor each controller one at a time. After each controller is refactored, run its tests to verify. The general pattern:

```php
// BEFORE (anti-pattern)
$attributes = $request->input('data.attributes', []);
$result = $this->service->create($attributes, $merchantAccountId);
return $this->jsonApiResource(model: $result, type: 'foo', attributes: [...], status: 201);

// AFTER (correct)
$dto = $request->toDto();
$result = $this->service->create($dto, $merchantAccountId);
return FooResource::make($result)->response()->setStatusCode(201)->header('Location', ...);
```

**Step 1: Refactor PaymentController**

Replace `JsonApiResponse` trait with `PaymentIntentResource`. Use `$request->toDto()` for store/confirm/capture. Remove `paymentAttributes()` private method.

**Step 2: Refactor RefundController**

Replace with `RefundResource`. Use `$request->toDto()` for store.

**Step 3: Refactor CustomerController**

Replace with `CustomerResource`. Use `$request->toDto()` for store/update. Use `CustomerResource::collection()` for index.

**Step 4: Refactor PaymentMethodController**

Replace with `PaymentMethodResource`. Inject `PaymentMethodService` instead of repositories. Use new `StorePaymentMethodRequest`. Remove `detectBrand()` (moved to service).

**Step 5: Refactor WebhookReceiverController**

Inject `WebhookReceiverService`. Reduce controller to ~10 lines — just call service and return response.

**Step 6: Refactor Admin controllers**

- OrganizationController → inject `MerchantService`, use `OrganizationResource`
- MerchantAccountController → inject `MerchantService`, use `MerchantAccountResource`
- BusinessProfileController → inject `MerchantService`, use `BusinessProfileResource`
- ApiKeyController → inject `MerchantService`, use `ApiKeyResource`
- ConnectorController → inject `ConnectorService`, use `ConnectorResource`, use `UpdateConnectorRequest`
- RoutingRuleController → use `StoreRoutingRuleRequest`/`UpdateRoutingRuleRequest`, use `RoutingRuleResource`

**Step 7: Run ALL tests**

Run: `./vendor/bin/pest`
Expected: Tests may need adjustment for new JSON:API response format from `timacdonald/json-api` (different structure than custom trait). Fix any failing tests.

**Step 8: Commit**

```bash
git add app/Http/Controllers/ app/Http/Requests/
git commit -m "refactor: wire all controllers to JsonApiResource classes, DTOs, and service layer"
```

---

## Task 8: Refactor PaymentService and RefundService — remove direct model access

**Files:**
- Modify: `app/Services/PaymentService.php`
- Modify: `app/Services/RefundService.php`
- Modify: `app/Repositories/Contracts/PaymentIntentRepositoryInterface.php`
- Modify: `app/Repositories/Eloquent/PaymentIntentRepository.php`

**Step 1: Add new repository methods for payment operations**

Add to `PaymentIntentRepositoryInterface`:
```php
public function updateStatus(PaymentIntent $payment, PaymentStatus $status, array $extra = []): PaymentIntent;
public function incrementAttemptCount(PaymentIntent $payment): void;
public function createAttempt(PaymentIntent $payment, array $data): void;
public function findLastSuccessfulAttempt(PaymentIntent $payment): ?object;
public function findPaymentMethodByKey(string $key, int|string $merchantAccountId): ?PaymentMethod;
```

**Step 2: Implement in PaymentIntentRepository**

Each method uses the model's relationships/queries — this is proper since repositories are allowed DB access.

**Step 3: Refactor PaymentService::confirm()**

Replace all `$payment->update([...])` with `$this->paymentRepository->updateStatus(...)`.
Replace `$payment->paymentAttempts()->create([...])` with `$this->paymentRepository->createAttempt(...)`.
Replace `$payment->increment('attempt_count')` with `$this->paymentRepository->incrementAttemptCount(...)`.
Replace `PaymentMethod::where(...)` with `$this->paymentRepository->findPaymentMethodByKey(...)`.

**Step 4: Refactor PaymentService::capture() and cancel()**

Same pattern — move all `$payment->update()` and relationship queries to repository.

**Step 5: Refactor RefundService::create()**

Move `$payment->paymentAttempts()->where(...)` to repository.

**Step 6: Update service method signatures to accept DTOs**

Change `create(array $data, ...)` → `create(CreatePaymentData $dto, ...)` etc.

**Step 7: Run tests**

Run: `./vendor/bin/pest`
Expected: All pass

**Step 8: Commit**

```bash
git add app/Services/ app/Repositories/
git commit -m "refactor: move all DB access from services to repositories, accept DTOs"
```

---

## Task 9: Connect QueryBuilders to Repositories

**Files:**
- Modify: `app/Repositories/Eloquent/CustomerRepository.php`
- Modify: `app/Repositories/Eloquent/PaymentIntentRepository.php`
- Modify: `app/Repositories/Eloquent/RefundRepository.php`
- Modify: `app/Repositories/Eloquent/MerchantRepository.php`
- Modify: `app/Repositories/Eloquent/WebhookEventRepository.php`

**Step 1: Unify QueryBuilder pattern**

`PaymentIntentQueryBuilder` extends `Builder` (different from others). Refactor to match the standalone pattern used by `CustomerQueryBuilder`, `RefundQueryBuilder`, etc.

**Step 2: Wire CustomerRepository to use CustomerQueryBuilder**

```php
// Before:
return Customer::where('key', $key)->where('merchant_account_id', $merchantAccountId)->firstOrFail();

// After:
return CustomerQueryBuilder::make()->forMerchant($merchantAccountId)->whereKey($key)->firstOrFail();
```

**Step 3: Wire all other repositories similarly**

Each repository gets a `private function query(): XxxQueryBuilder` helper method and uses it for all queries.

**Step 4: Run tests**

Run: `./vendor/bin/pest`
Expected: All pass

**Step 5: Commit**

```bash
git add app/Repositories/ app/Builders/
git commit -m "refactor: wire QueryBuilders into all repositories"
```

---

## Task 10: Wire Enums into services and remove magic strings

**Files:**
- Modify: `app/Services/RoutingService.php` — use `RoutingRuleType` enum
- Modify: `app/Services/WebhookReceiverService.php` — use `ConnectorName` enum (already done in Task 5)
- Modify: `app/Listeners/SendWebhookNotification.php` — use `WebhookEventType` enum
- Modify: `app/Services/PaymentService.php` — use `PaymentAttemptStatus` enum
- Modify: `app/Services/RefundService.php` — use `WebhookEventType` enum

**Step 1: Refactor RoutingService**

Replace `'priority'`, `'rule_based'`, `'volume_split'` strings with `RoutingRuleType::Priority->value` etc.

**Step 2: Refactor SendWebhookNotification**

Replace `'payment_succeeded'`, `'payment_cancelled'` etc. with `WebhookEventType::PaymentSucceeded->value`.

**Step 3: Refactor PaymentService attempt records**

Replace `'succeeded'`, `'failed'` strings with `PaymentAttemptStatus::Succeeded->value`.

**Step 4: Refactor RefundService webhook events**

Replace `'refund_succeeded'`, `'refund_failed'` with `WebhookEventType::RefundSucceeded->value`.

**Step 5: Run tests**

Run: `./vendor/bin/pest`
Expected: All pass

**Step 6: Commit**

```bash
git add app/Services/ app/Listeners/
git commit -m "refactor: replace magic strings with Enums throughout services and listeners"
```

---

## Task 11: Delete JsonApiResponse trait + cleanup

**Files:**
- Delete: `app/Http/Controllers/Api/V1/Concerns/JsonApiResponse.php`
- Verify: no remaining imports of the trait in any controller

**Step 1: Delete the trait file**

Run: `rm app/Http/Controllers/Api/V1/Concerns/JsonApiResponse.php`

**Step 2: Verify no references remain**

Run: `grep -r "JsonApiResponse" app/`
Expected: No results

**Step 3: Run full test suite**

Run: `./vendor/bin/pest`
Expected: All pass

**Step 4: Lint**

Run: `composer lint`
Expected: Clean

**Step 5: Commit**

```bash
git add -A
git commit -m "chore: remove deprecated JsonApiResponse trait"
```

---

## Task 12: Fix tests for new JSON:API response format

**Files:**
- Modify: all test files in `tests/Feature/Api/`

**Context:** The `timacdonald/json-api` package produces slightly different JSON structure than the custom trait. Tests asserting `$response->json('data.id')` etc. may need updates. The key differences:
- `links` is now an object with `Link::self()` format
- Collection responses include `included`, `links`, `meta` keys
- `relationships` key appears when relationships are defined

**Step 1: Run tests, collect all failures**

Run: `./vendor/bin/pest 2>&1 | head -200`

**Step 2: Fix each failing test**

Adjust JSON path assertions to match `timacdonald/json-api` output format.

**Step 3: Run full suite**

Run: `./vendor/bin/pest`
Expected: All pass

**Step 4: Run CI checks**

Run: `composer ci:check`
Expected: All pass

**Step 5: Commit**

```bash
git add tests/
git commit -m "test: update assertions for timacdonald/json-api response format"
```

---

## Summary: Task Dependencies

```
Task 1 (install package)
  → Task 2 (create resources)
  → Task 3 (create enums)        [parallel with 2]
  → Task 4 (create form requests) [parallel with 2, 3]
  → Task 5 (create services)
  → Task 6 (extend repositories)
  → Task 7 (wire controllers) ← depends on 2, 4, 5, 6
  → Task 8 (refactor payment/refund services) ← depends on 7
  → Task 9 (connect query builders) ← depends on 8
  → Task 10 (wire enums) ← depends on 3, 8
  → Task 11 (cleanup trait) ← depends on 7
  → Task 12 (fix tests) ← depends on all above
```

Tasks 2, 3, 4 can run in parallel after Task 1.
Tasks 5 and 6 can run in parallel.
Task 7 is the critical path — requires 2, 4, 5, 6.
Tasks 8, 9, 10, 11 can overlap but are sequential within their deps.
Task 12 should run last.
