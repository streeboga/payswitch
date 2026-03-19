# Payment Widget Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Create @payswitch/js — an embeddable payment widget SDK (loader + UI) and the Public API backend to power it.

**Architecture:** Three layers: (1) `AuthenticateClientSecret` middleware + `PublicPaymentController` — public API endpoints authenticated via publishable_key + client_secret, (2) `/widget/` TypeScript package — SDK loader + Preact UI that renders payment methods and handles redirect flow, (3) Dashboard integration — replace test-payment page with widget.

**Tech Stack:** PHP 8.4 / Laravel 13 / Pest (backend), TypeScript / Preact / Vite library mode (widget), Playwright (E2E)

**Design doc:** `docs/plans/2026-03-20-payment-widget-design.md`

---

## Task 1: AuthenticateClientSecret Middleware

**Files:**
- Create: `app/Http/Middleware/AuthenticateClientSecret.php`
- Modify: `bootstrap/app.php:43-49` (add alias)
- Test: `tests/Feature/Middleware/AuthenticateClientSecretTest.php`

**Step 1: Write failing tests**

```php
// tests/Feature/Middleware/AuthenticateClientSecretTest.php
<?php

use App\Http\Middleware\AuthenticateClientSecret;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\PaymentIntent;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create([
        'org_id' => $org->id,
        'name' => 'Test Merchant',
    ]);
    $this->payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'amount' => 10000,
        'currency' => 'RUB',
        'session_expiry' => 900,
        'expires_on' => now()->addMinutes(15),
    ]);
});

test('passes with valid client_secret in body', function () {
    $request = Request::create('/api/v1/payments/'.$this->payment->key.'/confirm', 'POST', [
        'client_secret' => $this->payment->client_secret,
    ]);
    $request->attributes->set('api_key_type', 'publishable');
    $request->attributes->set('merchant_id', $this->merchant->id);
    $request->setRouteResolver(fn () => new class ($this->payment->key) {
        public function __construct(private string $key) {}
        public function parameter(string $name) { return $this->key; }
    });

    $middleware = new AuthenticateClientSecret;
    $response = $middleware->handle($request, fn ($req) => new JsonResponse(['ok' => true]));

    expect($response->getStatusCode())->toBe(200);
    expect($request->attributes->get('payment_intent')->id)->toBe($this->payment->id);
});

test('passes with valid client_secret in query param', function () {
    $request = Request::create(
        '/api/v1/payments/'.$this->payment->key.'?client_secret='.$this->payment->client_secret,
        'GET'
    );
    $request->attributes->set('api_key_type', 'publishable');
    $request->attributes->set('merchant_id', $this->merchant->id);
    $request->setRouteResolver(fn () => new class ($this->payment->key) {
        public function __construct(private string $key) {}
        public function parameter(string $name) { return $this->key; }
    });

    $middleware = new AuthenticateClientSecret;
    $response = $middleware->handle($request, fn ($req) => new JsonResponse(['ok' => true]));

    expect($response->getStatusCode())->toBe(200);
});

test('rejects missing client_secret', function () {
    $request = Request::create('/api/v1/payments/'.$this->payment->key.'/confirm', 'POST');
    $request->attributes->set('api_key_type', 'publishable');
    $request->attributes->set('merchant_id', $this->merchant->id);
    $request->setRouteResolver(fn () => new class ($this->payment->key) {
        public function __construct(private string $key) {}
        public function parameter(string $name) { return $this->key; }
    });

    $middleware = new AuthenticateClientSecret;
    $response = $middleware->handle($request, fn ($req) => new JsonResponse(['ok' => true]));

    expect($response->getStatusCode())->toBe(403);
    expect($response->getData(true)['errors'][0]['detail'])->toContain('client_secret');
});

test('rejects invalid client_secret', function () {
    $request = Request::create('/api/v1/payments/'.$this->payment->key.'/confirm', 'POST', [
        'client_secret' => 'wrong_secret',
    ]);
    $request->attributes->set('api_key_type', 'publishable');
    $request->attributes->set('merchant_id', $this->merchant->id);
    $request->setRouteResolver(fn () => new class ($this->payment->key) {
        public function __construct(private string $key) {}
        public function parameter(string $name) { return $this->key; }
    });

    $middleware = new AuthenticateClientSecret;
    $response = $middleware->handle($request, fn ($req) => new JsonResponse(['ok' => true]));

    expect($response->getStatusCode())->toBe(403);
});

test('rejects expired payment session', function () {
    $this->payment->update(['expires_on' => now()->subMinute()]);

    $request = Request::create('/api/v1/payments/'.$this->payment->key.'/confirm', 'POST', [
        'client_secret' => $this->payment->client_secret,
    ]);
    $request->attributes->set('api_key_type', 'publishable');
    $request->attributes->set('merchant_id', $this->merchant->id);
    $request->setRouteResolver(fn () => new class ($this->payment->key) {
        public function __construct(private string $key) {}
        public function parameter(string $name) { return $this->key; }
    });

    $middleware = new AuthenticateClientSecret;
    $response = $middleware->handle($request, fn ($req) => new JsonResponse(['ok' => true]));

    expect($response->getStatusCode())->toBe(403);
    expect($response->getData(true)['errors'][0]['detail'])->toContain('expired');
});

test('rejects when publishable key merchant does not own payment', function () {
    $otherOrg = Organization::create(['name' => 'Other']);
    $otherMerchant = MerchantAccount::create(['org_id' => $otherOrg->id, 'name' => 'Other']);

    $request = Request::create('/api/v1/payments/'.$this->payment->key.'/confirm', 'POST', [
        'client_secret' => $this->payment->client_secret,
    ]);
    $request->attributes->set('api_key_type', 'publishable');
    $request->attributes->set('merchant_id', $otherMerchant->id);
    $request->setRouteResolver(fn () => new class ($this->payment->key) {
        public function __construct(private string $key) {}
        public function parameter(string $name) { return $this->key; }
    });

    $middleware = new AuthenticateClientSecret;
    $response = $middleware->handle($request, fn ($req) => new JsonResponse(['ok' => true]));

    expect($response->getStatusCode())->toBe(403);
});
```

**Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Middleware/AuthenticateClientSecretTest.php`
Expected: FAIL — class not found

**Step 3: Implement middleware**

```php
// app/Http/Middleware/AuthenticateClientSecret.php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Streeboga\PaymentData\Models\PaymentIntent;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateClientSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $clientSecret = $request->input('client_secret') ?? $request->query('client_secret');

        if (! $clientSecret) {
            return $this->errorResponse('client_secret is required', 'client_secret_required');
        }

        $paymentKey = $request->route()->parameter('paymentKey');
        $merchantId = $request->attributes->get('merchant_id');

        $payment = PaymentIntent::where('key', $paymentKey)
            ->where('merchant_account_id', $merchantId)
            ->first();

        if (! $payment || ! hash_equals($payment->client_secret, $clientSecret)) {
            return $this->errorResponse('Invalid client_secret', 'invalid_client_secret');
        }

        if ($payment->expires_on && $payment->expires_on->isPast()) {
            return $this->errorResponse('Payment session has expired', 'session_expired');
        }

        $request->attributes->set('payment_intent', $payment);

        return $next($request);
    }

    private function errorResponse(string $detail, string $code): Response
    {
        return response()->json([
            'errors' => [
                [
                    'status' => '403',
                    'code' => $code,
                    'detail' => $detail,
                ],
            ],
        ], 403);
    }
}
```

**Step 4: Register middleware alias**

In `bootstrap/app.php`, add to the aliases array:

```php
'auth.client_secret' => \App\Http\Middleware\AuthenticateClientSecret::class,
```

**Step 5: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/Middleware/AuthenticateClientSecretTest.php`
Expected: 6 tests PASS

**Step 6: Commit**

```bash
git add app/Http/Middleware/AuthenticateClientSecret.php bootstrap/app.php tests/Feature/Middleware/AuthenticateClientSecretTest.php
git commit -m "feat: add AuthenticateClientSecret middleware for public API"
```

---

## Task 2: PublicPaymentController + Routes

**Files:**
- Create: `app/Http/Controllers/Api/V1/PublicPaymentController.php`
- Create: `app/Http/Resources/PublicPaymentIntentResource.php`
- Modify: `routes/api.php` (add public routes group)
- Test: `tests/Feature/Api/Payments/PublicPaymentApiTest.php`

**Step 1: Write failing tests**

```php
// tests/Feature/Api/Payments/PublicPaymentApiTest.php
<?php

use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\Enums\PaymentStatus;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    $this->profile = BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);

    MerchantConnectorAccount::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->profile->id,
        'connector_name' => 'test',
        'connector_type' => 'fiz_operations',
        'connector_account_details' => ['auth_type' => 'HeaderKey', 'api_key' => 'sk_test_xxx'],
        'payment_methods_enabled' => [['payment_method' => 'card']],
        'test_mode' => true,
    ]);

    $this->payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'business_profile_id' => $this->profile->id,
        'amount' => 10000,
        'currency' => 'RUB',
        'return_url' => 'https://merchant.example.com/result',
        'session_expiry' => 900,
        'expires_on' => now()->addMinutes(15),
    ]);
});

function publicHeaders($merchant): array
{
    return ['api-key' => $merchant->publishable_key];
}

// --- show() ---

test('public show returns limited payment fields', function () {
    $response = $this->getJson(
        '/api/v1/payments/'.$this->payment->key.'?client_secret='.$this->payment->client_secret,
        publicHeaders($this->merchant),
    );

    $response->assertOk();
    $attrs = $response->json('data.attributes');
    expect($attrs)->toHaveKeys(['status', 'amount', 'currency']);
    expect($attrs)->not->toHaveKey('client_secret');
    expect($attrs)->not->toHaveKey('connector');
});

test('public show rejects without client_secret', function () {
    $response = $this->getJson(
        '/api/v1/payments/'.$this->payment->key,
        publicHeaders($this->merchant),
    );

    $response->assertStatus(403);
});

// --- paymentMethods() ---

test('payment methods returns enabled methods for the profile', function () {
    $response = $this->getJson(
        '/api/v1/payments/'.$this->payment->key.'/payment-methods?client_secret='.$this->payment->client_secret,
        publicHeaders($this->merchant),
    );

    $response->assertOk();
    $methods = $response->json('data');
    expect($methods)->toBeArray();
    expect($methods)->not->toBeEmpty();
    expect($methods[0])->toHaveKey('payment_method', 'card');
});

test('payment methods returns empty when no active connectors', function () {
    MerchantConnectorAccount::query()->update(['disabled' => true]);

    $response = $this->getJson(
        '/api/v1/payments/'.$this->payment->key.'/payment-methods?client_secret='.$this->payment->client_secret,
        publicHeaders($this->merchant),
    );

    $response->assertOk();
    expect($response->json('data'))->toBeEmpty();
});

// --- confirm() ---

test('public confirm triggers redirect flow', function () {
    $response = $this->postJson(
        '/api/v1/payments/'.$this->payment->key.'/confirm',
        [
            'client_secret' => $this->payment->client_secret,
            'payment_method' => 'card',
        ],
        publicHeaders($this->merchant),
    );

    $response->assertOk()
        ->assertJsonPath('data.attributes.status', 'requires_customer_action');

    $metadata = $response->json('data.attributes.metadata');
    expect($metadata)->toHaveKey('redirect_url');
    expect($metadata['redirect_url'])->toContain('https://test-psp.example.com/pay/');
});

test('public confirm rejects with invalid client_secret', function () {
    $response = $this->postJson(
        '/api/v1/payments/'.$this->payment->key.'/confirm',
        [
            'client_secret' => 'invalid_secret',
            'payment_method' => 'card',
        ],
        publicHeaders($this->merchant),
    );

    $response->assertStatus(403);
});

test('public confirm rejects expired session', function () {
    $this->payment->update(['expires_on' => now()->subMinute()]);

    $response = $this->postJson(
        '/api/v1/payments/'.$this->payment->key.'/confirm',
        [
            'client_secret' => $this->payment->client_secret,
            'payment_method' => 'card',
        ],
        publicHeaders($this->merchant),
    );

    $response->assertStatus(403);
});
```

**Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Api/Payments/PublicPaymentApiTest.php`
Expected: FAIL — routes/controller not found

**Step 3: Create PublicPaymentIntentResource**

```php
// app/Http/Resources/PublicPaymentIntentResource.php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use TiMacDonald\JsonApi\JsonApiResource;

class PublicPaymentIntentResource extends JsonApiResource
{
    public function toAttributes(Request $request): array
    {
        return [
            'status' => $this->status->value,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'description' => $this->description,
            'metadata' => $this->filterPublicMetadata($this->metadata),
        ];
    }

    private function filterPublicMetadata(?array $metadata): ?array
    {
        if (! $metadata) {
            return null;
        }

        return array_intersect_key($metadata, array_flip([
            'redirect_url',
            'redirect_method',
        ]));
    }
}
```

**Step 4: Create PublicPaymentController**

```php
// app/Http/Controllers/Api/V1/PublicPaymentController.php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicPaymentIntentResource;
use App\Services\PaymentService;
use App\DataTransferObjects\Payment\ConfirmPaymentData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;

class PublicPaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService,
    ) {}

    public function show(string $paymentKey, Request $request): JsonResponse
    {
        $payment = $request->attributes->get('payment_intent');

        return (new PublicPaymentIntentResource($payment))->toResponse($request);
    }

    public function confirm(string $paymentKey, Request $request): JsonResponse
    {
        $request->validate([
            'payment_method' => ['required', 'string'],
            'payment_method_data' => ['sometimes', 'array'],
            'connector' => ['sometimes', 'string'],
        ]);

        $dto = ConfirmPaymentData::from($request->only([
            'payment_method',
            'payment_method_data',
            'connector',
        ]));

        $merchantAccountId = $request->attributes->get('merchant_id');
        $payment = $this->paymentService->confirm($paymentKey, $dto, $merchantAccountId);

        return (new PublicPaymentIntentResource($payment))->toResponse($request);
    }

    public function paymentMethods(string $paymentKey, Request $request): JsonResponse
    {
        $payment = $request->attributes->get('payment_intent');

        $connectors = MerchantConnectorAccount::query()
            ->where('business_profile_id', $payment->business_profile_id)
            ->where('disabled', false)
            ->whereNotNull('payment_methods_enabled')
            ->get();

        $methods = $connectors
            ->flatMap(fn ($mca) => $mca->payment_methods_enabled ?? [])
            ->unique('payment_method')
            ->values();

        return response()->json(['data' => $methods]);
    }
}
```

**Step 5: Add public routes**

In `routes/api.php`, add a new group **before** the existing merchant API group (inside the `v1` prefix):

```php
// Public API — authenticated via publishable_key + client_secret
Route::middleware(['auth.api_key', 'auth.client_secret', 'throttle:payswitch-api'])->group(function () {
    Route::get('/payments/{paymentKey}', [PublicPaymentController::class, 'show'])->name('api.v1.public.payments.show');
    Route::post('/payments/{paymentKey}/confirm', [PublicPaymentController::class, 'confirm'])->name('api.v1.public.payments.confirm');
    Route::get('/payments/{paymentKey}/payment-methods', [PublicPaymentController::class, 'paymentMethods'])->name('api.v1.public.payments.payment-methods');
});
```

**Important:** These routes must be registered **before** the secret_api_key group so they match first when using a publishable key. The `ResolveApiKey` middleware already sets `api_key_type='publishable'` for publishable keys — the `auth.client_secret` middleware validates the client_secret. The `auth.secret_api_key` middleware on the existing routes will reject publishable keys, so there's no conflict.

**Step 6: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/Api/Payments/PublicPaymentApiTest.php`
Expected: 7 tests PASS

**Step 7: Commit**

```bash
git add app/Http/Controllers/Api/V1/PublicPaymentController.php app/Http/Resources/PublicPaymentIntentResource.php routes/api.php tests/Feature/Api/Payments/PublicPaymentApiTest.php
git commit -m "feat: add Public Payment API with client_secret auth"
```

---

## Task 3: CORS for Public API

**Files:**
- Modify: `config/cors.php`
- Test: `tests/Feature/Api/Payments/PublicPaymentApiTest.php` (add CORS test)

**Step 1: Add CORS test**

Append to `PublicPaymentApiTest.php`:

```php
test('public API returns CORS headers for cross-origin requests', function () {
    $response = $this->withHeaders([
        'Origin' => 'https://merchant-site.example.com',
        ...publicHeaders($this->merchant),
    ])->getJson(
        '/api/v1/payments/'.$this->payment->key.'?client_secret='.$this->payment->client_secret,
    );

    $response->assertOk();
    $response->assertHeader('Access-Control-Allow-Origin');
});
```

**Step 2: Run test, verify it fails**

Run: `./vendor/bin/pest tests/Feature/Api/Payments/PublicPaymentApiTest.php --filter=CORS`
Expected: FAIL — no CORS header (origin not in allowed list)

**Step 3: Update CORS config**

In `config/cors.php`, change `allowed_origins` to accept all origins for the public API:

```php
'allowed_origins' => ['*'],
'supports_credentials' => false,
```

Or, if you need to keep credentials for the dashboard, use `allowed_origins_patterns`:

```php
'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:3000')],
'allowed_origins_patterns' => ['*'],
'supports_credentials' => false,
```

**Note:** Check how this interacts with Sanctum's `supports_credentials: true`. You may need to split CORS config into two middleware groups. If the global change breaks dashboard auth, create a dedicated `PublicCors` middleware instead.

**Step 4: Run test, verify it passes**

Run: `./vendor/bin/pest tests/Feature/Api/Payments/PublicPaymentApiTest.php --filter=CORS`
Expected: PASS

**Step 5: Commit**

```bash
git add config/cors.php tests/Feature/Api/Payments/PublicPaymentApiTest.php
git commit -m "feat: enable CORS for public payment API"
```

---

## Task 4: Widget Package Scaffold (`/widget/`)

**Files:**
- Create: `widget/package.json`
- Create: `widget/tsconfig.json`
- Create: `widget/vite.config.ts`
- Create: `widget/src/index.ts` (stub export)
- Create: `widget/src/types.ts`

**Step 1: Create package.json**

```json
{
  "name": "@payswitch/js",
  "version": "0.1.0",
  "private": true,
  "type": "module",
  "main": "dist/payswitch.js",
  "module": "dist/payswitch.mjs",
  "types": "dist/payswitch.d.ts",
  "exports": {
    ".": {
      "types": "./dist/payswitch.d.ts",
      "import": "./dist/payswitch.mjs",
      "require": "./dist/payswitch.js"
    }
  },
  "scripts": {
    "dev": "vite build --watch",
    "build": "tsc --noEmit && vite build",
    "test": "vitest run",
    "test:watch": "vitest",
    "lint": "eslint . --fix",
    "lint:check": "eslint .",
    "types:check": "tsc --noEmit"
  },
  "dependencies": {
    "preact": "^10.25.0"
  },
  "devDependencies": {
    "typescript": "^5.9.0",
    "vite": "^8.0.0",
    "vite-plugin-dts": "^4.5.0",
    "vitest": "^3.2.0",
    "happy-dom": "^17.4.0"
  }
}
```

**Step 2: Create tsconfig.json**

```json
{
  "compilerOptions": {
    "target": "ES2020",
    "module": "ESNext",
    "moduleResolution": "bundler",
    "lib": ["ES2020", "DOM", "DOM.Iterable"],
    "strict": true,
    "esModuleInterop": true,
    "skipLibCheck": true,
    "declaration": true,
    "declarationDir": "dist",
    "outDir": "dist",
    "jsx": "react-jsx",
    "jsxImportSource": "preact"
  },
  "include": ["src"]
}
```

**Step 3: Create vite.config.ts**

```ts
import { defineConfig } from 'vite';
import dts from 'vite-plugin-dts';

export default defineConfig({
  plugins: [dts({ rollupTypes: true })],
  build: {
    lib: {
      entry: 'src/index.ts',
      name: 'Payswitch',
      formats: ['es', 'umd'],
      fileName: (format) => `payswitch.${format === 'es' ? 'mjs' : 'js'}`,
    },
    rollupOptions: {
      // Preact is bundled (not external) — widget must be self-contained
    },
  },
  test: {
    environment: 'happy-dom',
  },
});
```

**Step 4: Create types.ts**

```ts
// widget/src/types.ts

export interface LoadOptions {
  customBackendUrl?: string;
  env?: 'sandbox' | 'production';
}

export interface PayswitchInstance {
  widgets(options: WidgetOptions): WidgetCollection;
  confirmPayment(params: ConfirmPaymentParams): Promise<ConfirmPaymentResult>;
  retrievePaymentIntent(clientSecret: string): Promise<PaymentIntentResponse>;
}

export interface WidgetOptions {
  clientSecret: string;
  appearance?: AppearanceOptions;
  locale?: string;
}

export interface WidgetCollection {
  create(type: 'payment', options?: Record<string, unknown>): PaymentWidget;
  getElement(type: string): PaymentWidget | null;
  update(options: Partial<WidgetOptions>): void;
}

export interface PaymentWidget {
  mount(selector: string | HTMLElement): void;
  unmount(): void;
  destroy(): void;
  on(event: WidgetEvent, handler: WidgetEventHandler): void;
  update(options: Record<string, unknown>): void;
}

export type WidgetEvent = 'ready' | 'change' | 'redirect' | 'error';
export type WidgetEventHandler = (data: unknown) => void;

export interface ConfirmPaymentParams {
  widgets: WidgetCollection;
  confirmParams: { return_url: string };
  redirect?: 'always' | 'if_required';
}

export type ConfirmPaymentResult =
  | { status: 'succeeded'; paymentIntent: PaymentIntentResponse }
  | { status: 'requires_customer_action'; redirectUrl: string }
  | { status: 'error'; error: { type: string; message: string } };

export interface PaymentIntentResponse {
  status: string;
  amount: number;
  currency: string;
  description?: string;
  metadata?: {
    redirect_url?: string;
    redirect_method?: string;
  };
}

export interface PaymentMethodInfo {
  payment_method: string;
}

export interface AppearanceOptions {
  theme?: 'default' | 'dark' | 'minimal';
  variables?: Record<string, string>;
}
```

**Step 5: Create index.ts stub**

```ts
// widget/src/index.ts
import type { PayswitchInstance, LoadOptions } from './types';

export type { PayswitchInstance, LoadOptions, WidgetOptions, WidgetCollection, PaymentWidget } from './types';

export async function loadPayswitch(
  publishableKey: string,
  options?: LoadOptions,
): Promise<PayswitchInstance> {
  throw new Error('Not implemented');
}
```

**Step 6: Install deps and verify build**

Run:
```bash
cd widget && npm install && npm run build
```
Expected: Build succeeds, produces `dist/payswitch.mjs` and `dist/payswitch.js`

**Step 7: Commit**

```bash
git add widget/
git commit -m "feat: scaffold @payswitch/js widget package"
```

---

## Task 5: API Client (`widget/src/api.ts`)

**Files:**
- Create: `widget/src/api.ts`
- Test: `widget/src/api.test.ts`

**Step 1: Write failing tests**

```ts
// widget/src/api.test.ts
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { PaymentApi } from './api';

const mockFetch = vi.fn();

beforeEach(() => {
  vi.stubGlobal('fetch', mockFetch);
  mockFetch.mockReset();
});

describe('PaymentApi', () => {
  const api = new PaymentApi('https://api.example.com', 'pk_test_xxx');

  it('getPayment sends correct headers and client_secret', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({ data: { attributes: { status: 'requires_payment_method', amount: 1000, currency: 'RUB' } } }),
    });

    const result = await api.getPayment('pi_xxx', 'pi_xxx_secret_yyy');
    expect(mockFetch).toHaveBeenCalledWith(
      'https://api.example.com/api/v1/payments/pi_xxx?client_secret=pi_xxx_secret_yyy',
      expect.objectContaining({
        headers: expect.objectContaining({ 'api-key': 'pk_test_xxx' }),
      }),
    );
    expect(result.status).toBe('requires_payment_method');
  });

  it('getPaymentMethods returns array of methods', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({ data: [{ payment_method: 'card' }] }),
    });

    const result = await api.getPaymentMethods('pi_xxx', 'pi_xxx_secret_yyy');
    expect(result).toEqual([{ payment_method: 'card' }]);
  });

  it('confirmPayment sends client_secret in body', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({
        data: { attributes: { status: 'requires_customer_action', metadata: { redirect_url: 'https://psp.example.com/pay' } } },
      }),
    });

    const result = await api.confirmPayment('pi_xxx', {
      client_secret: 'pi_xxx_secret_yyy',
      payment_method: 'card',
    });

    const [, opts] = mockFetch.mock.calls[0];
    const body = JSON.parse(opts.body);
    expect(body.client_secret).toBe('pi_xxx_secret_yyy');
    expect(body.payment_method).toBe('card');
    expect(result.status).toBe('requires_customer_action');
  });

  it('throws on non-ok response', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: false,
      status: 403,
      json: () => Promise.resolve({ errors: [{ detail: 'Invalid client_secret' }] }),
    });

    await expect(api.getPayment('pi_xxx', 'bad_secret')).rejects.toThrow();
  });
});
```

**Step 2: Run tests to verify they fail**

Run: `cd widget && npx vitest run src/api.test.ts`
Expected: FAIL — module not found

**Step 3: Implement API client**

```ts
// widget/src/api.ts
import type { PaymentIntentResponse, PaymentMethodInfo } from './types';

export class PaymentApi {
  constructor(
    private baseUrl: string,
    private publishableKey: string,
  ) {}

  async getPayment(paymentKey: string, clientSecret: string): Promise<PaymentIntentResponse> {
    const url = `${this.baseUrl}/api/v1/payments/${paymentKey}?client_secret=${encodeURIComponent(clientSecret)}`;
    const res = await this.request(url, { method: 'GET' });
    return res.data.attributes;
  }

  async getPaymentMethods(paymentKey: string, clientSecret: string): Promise<PaymentMethodInfo[]> {
    const url = `${this.baseUrl}/api/v1/payments/${paymentKey}/payment-methods?client_secret=${encodeURIComponent(clientSecret)}`;
    const res = await this.request(url, { method: 'GET' });
    return res.data;
  }

  async confirmPayment(
    paymentKey: string,
    body: { client_secret: string; payment_method: string; [key: string]: unknown },
  ): Promise<PaymentIntentResponse> {
    const url = `${this.baseUrl}/api/v1/payments/${paymentKey}/confirm`;
    const res = await this.request(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/vnd.api+json' },
      body: JSON.stringify(body),
    });
    return res.data.attributes;
  }

  private async request(url: string, init: RequestInit = {}): Promise<any> {
    const res = await fetch(url, {
      ...init,
      headers: {
        'api-key': this.publishableKey,
        Accept: 'application/vnd.api+json',
        ...init.headers,
      },
    });

    if (!res.ok) {
      const error = await res.json().catch(() => ({}));
      throw new Error(error.errors?.[0]?.detail ?? `HTTP ${res.status}`);
    }

    return res.json();
  }
}
```

**Step 4: Run tests to verify they pass**

Run: `cd widget && npx vitest run src/api.test.ts`
Expected: 4 tests PASS

**Step 5: Commit**

```bash
git add widget/src/api.ts widget/src/api.test.ts
git commit -m "feat: widget API client for public payment endpoints"
```

---

## Task 6: SDK Loader (`widget/src/index.ts`)

**Files:**
- Modify: `widget/src/index.ts`
- Create: `widget/src/payswitch.ts`
- Test: `widget/src/index.test.ts`

**Step 1: Write failing tests**

```ts
// widget/src/index.test.ts
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { loadPayswitch } from './index';

beforeEach(() => {
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
    ok: true,
    json: () => Promise.resolve({ data: { attributes: { status: 'requires_payment_method', amount: 1000, currency: 'RUB' } } }),
  }));
});

describe('loadPayswitch', () => {
  it('returns a PayswitchInstance', async () => {
    const ps = await loadPayswitch('pk_test_xxx');
    expect(ps).toBeDefined();
    expect(ps.widgets).toBeTypeOf('function');
    expect(ps.confirmPayment).toBeTypeOf('function');
    expect(ps.retrievePaymentIntent).toBeTypeOf('function');
  });

  it('rejects empty publishable key', async () => {
    await expect(loadPayswitch('')).rejects.toThrow('publishableKey is required');
  });

  it('uses sandbox URL for pk_snd_ prefix', async () => {
    const ps = await loadPayswitch('pk_snd_xxx', { env: 'sandbox' });
    expect(ps).toBeDefined();
  });

  it('uses custom backend URL when provided', async () => {
    const ps = await loadPayswitch('pk_test_xxx', {
      customBackendUrl: 'https://custom.example.com',
    });
    expect(ps).toBeDefined();
  });
});
```

**Step 2: Run tests to verify they fail**

Run: `cd widget && npx vitest run src/index.test.ts`
Expected: FAIL — rejects due to 'Not implemented'

**Step 3: Implement PayswitchInstance**

```ts
// widget/src/payswitch.ts
import { PaymentApi } from './api';
import type {
  PayswitchInstance,
  WidgetOptions,
  WidgetCollection,
  ConfirmPaymentParams,
  ConfirmPaymentResult,
  PaymentIntentResponse,
  PaymentWidget,
  WidgetEventHandler,
  WidgetEvent,
  PaymentMethodInfo,
} from './types';

export function createPayswitchInstance(
  publishableKey: string,
  baseUrl: string,
): PayswitchInstance {
  const api = new PaymentApi(baseUrl, publishableKey);

  return {
    widgets(options: WidgetOptions): WidgetCollection {
      return createWidgetCollection(api, options);
    },

    async confirmPayment(params: ConfirmPaymentParams): Promise<ConfirmPaymentResult> {
      const collection = params.widgets as WidgetCollectionImpl;
      const { clientSecret } = collection.options;
      const paymentKey = extractPaymentKey(clientSecret);

      try {
        const result = await api.confirmPayment(paymentKey, {
          client_secret: clientSecret,
          payment_method: collection.selectedMethod ?? 'card',
        });

        if (result.status === 'requires_customer_action' && result.metadata?.redirect_url) {
          if (params.redirect !== 'if_required') {
            window.location.href = result.metadata.redirect_url;
          }
          return { status: 'requires_customer_action', redirectUrl: result.metadata.redirect_url };
        }

        if (result.status === 'succeeded' || result.status === 'requires_capture') {
          return { status: 'succeeded', paymentIntent: result };
        }

        return { status: 'error', error: { type: 'api_error', message: `Unexpected status: ${result.status}` } };
      } catch (e) {
        return { status: 'error', error: { type: 'api_error', message: (e as Error).message } };
      }
    },

    async retrievePaymentIntent(clientSecret: string): Promise<PaymentIntentResponse> {
      const paymentKey = extractPaymentKey(clientSecret);
      return api.getPayment(paymentKey, clientSecret);
    },
  };
}

class WidgetCollectionImpl implements WidgetCollection {
  private widgets = new Map<string, PaymentWidgetImpl>();
  selectedMethod: string | null = null;

  constructor(
    private api: PaymentApi,
    public options: WidgetOptions,
  ) {}

  create(type: 'payment'): PaymentWidget {
    const widget = new PaymentWidgetImpl(this.api, this.options, this);
    this.widgets.set(type, widget);
    return widget;
  }

  getElement(type: string): PaymentWidget | null {
    return this.widgets.get(type) ?? null;
  }

  update(options: Partial<WidgetOptions>): void {
    Object.assign(this.options, options);
  }
}

function createWidgetCollection(api: PaymentApi, options: WidgetOptions): WidgetCollection {
  return new WidgetCollectionImpl(api, options);
}

class PaymentWidgetImpl implements PaymentWidget {
  private container: HTMLElement | null = null;
  private listeners = new Map<WidgetEvent, Set<WidgetEventHandler>>();
  private methods: PaymentMethodInfo[] = [];

  constructor(
    private api: PaymentApi,
    private options: WidgetOptions,
    private collection: WidgetCollectionImpl,
  ) {}

  async mount(selector: string | HTMLElement): Promise<void> {
    this.container =
      typeof selector === 'string' ? document.querySelector(selector) : selector;

    if (!this.container) {
      throw new Error(`Element not found: ${selector}`);
    }

    this.container.innerHTML = '<div class="ps-widget ps-loading">Loading...</div>';

    try {
      const paymentKey = extractPaymentKey(this.options.clientSecret);
      this.methods = await this.api.getPaymentMethods(paymentKey, this.options.clientSecret);
      this.render();
      this.emit('ready', {});
    } catch (e) {
      this.emit('error', { message: (e as Error).message });
      this.container.innerHTML = `<div class="ps-widget ps-error">${(e as Error).message}</div>`;
    }
  }

  unmount(): void {
    if (this.container) {
      this.container.innerHTML = '';
    }
  }

  destroy(): void {
    this.unmount();
    this.listeners.clear();
    this.container = null;
  }

  on(event: WidgetEvent, handler: WidgetEventHandler): void {
    if (!this.listeners.has(event)) {
      this.listeners.set(event, new Set());
    }
    this.listeners.get(event)!.add(handler);
  }

  update(): void {
    if (this.container && this.methods.length > 0) {
      this.render();
    }
  }

  private emit(event: WidgetEvent, data: unknown): void {
    this.listeners.get(event)?.forEach((handler) => handler(data));
  }

  private render(): void {
    if (!this.container) return;

    const methodsHtml = this.methods
      .map(
        (m) =>
          `<label class="ps-method">
            <input type="radio" name="ps-method" value="${m.payment_method}" ${this.collection.selectedMethod === m.payment_method ? 'checked' : ''} />
            <span>${methodLabel(m.payment_method)}</span>
          </label>`,
      )
      .join('');

    this.container.innerHTML = `
      <div class="ps-widget">
        <div class="ps-methods">${methodsHtml}</div>
      </div>
    `;

    // Bind radio selection
    this.container.querySelectorAll<HTMLInputElement>('input[name="ps-method"]').forEach((input) => {
      input.addEventListener('change', () => {
        this.collection.selectedMethod = input.value;
        this.emit('change', { paymentMethod: input.value });
      });
    });

    // Auto-select first method
    if (!this.collection.selectedMethod && this.methods.length > 0) {
      this.collection.selectedMethod = this.methods[0].payment_method;
    }
  }
}

function extractPaymentKey(clientSecret: string): string {
  const idx = clientSecret.indexOf('_secret_');
  if (idx === -1) throw new Error('Invalid client_secret format');
  return clientSecret.substring(0, idx);
}

const METHOD_LABELS: Record<string, string> = {
  card: 'Card',
  bank_transfer: 'Bank Transfer',
  sbp: 'SBP (СБП)',
  qr_code: 'QR Code',
};

function methodLabel(method: string): string {
  return METHOD_LABELS[method] ?? method;
}
```

**Step 4: Update index.ts**

```ts
// widget/src/index.ts
import { createPayswitchInstance } from './payswitch';
import type { PayswitchInstance, LoadOptions } from './types';

export type {
  PayswitchInstance,
  LoadOptions,
  WidgetOptions,
  WidgetCollection,
  PaymentWidget,
  ConfirmPaymentParams,
  ConfirmPaymentResult,
  PaymentIntentResponse,
} from './types';

const DEFAULT_URLS: Record<string, string> = {
  sandbox: '',  // Will be configured per deployment
  production: '',
};

export async function loadPayswitch(
  publishableKey: string,
  options?: LoadOptions,
): Promise<PayswitchInstance> {
  if (!publishableKey) {
    throw new Error('publishableKey is required');
  }

  const env = options?.env ?? (publishableKey.startsWith('pk_prd_') ? 'production' : 'sandbox');
  const baseUrl = options?.customBackendUrl ?? DEFAULT_URLS[env] ?? '';

  return createPayswitchInstance(publishableKey, baseUrl);
}
```

**Step 5: Run tests to verify they pass**

Run: `cd widget && npx vitest run src/index.test.ts`
Expected: 4 tests PASS

**Step 6: Commit**

```bash
git add widget/src/index.ts widget/src/payswitch.ts widget/src/index.test.ts
git commit -m "feat: implement loadPayswitch SDK loader and PayswitchInstance"
```

---

## Task 7: Widget UI (Preact render)

**Files:**
- Create: `widget/src/ui/PaymentWidget.tsx`
- Create: `widget/src/ui/styles.css`
- Modify: `widget/src/payswitch.ts` (use Preact render instead of innerHTML)

**Step 1: Create PaymentWidget Preact component**

```tsx
// widget/src/ui/PaymentWidget.tsx
import { h, render as preactRender } from 'preact';
import { useState, useEffect } from 'preact/hooks';
import type { PaymentMethodInfo } from '../types';

interface PaymentWidgetProps {
  methods: PaymentMethodInfo[];
  selectedMethod: string | null;
  onMethodChange: (method: string) => void;
  loading?: boolean;
  error?: string | null;
}

const METHOD_ICONS: Record<string, string> = {
  card: '💳',
  bank_transfer: '🏦',
  sbp: '📱',
  qr_code: '📷',
};

const METHOD_LABELS: Record<string, string> = {
  card: 'Bank Card',
  bank_transfer: 'Bank Transfer',
  sbp: 'SBP (СБП)',
  qr_code: 'QR Code',
};

function PaymentWidgetUI({ methods, selectedMethod, onMethodChange, loading, error }: PaymentWidgetProps) {
  if (loading) {
    return <div class="ps-widget ps-loading"><div class="ps-spinner" /></div>;
  }

  if (error) {
    return <div class="ps-widget ps-error">{error}</div>;
  }

  if (methods.length === 0) {
    return <div class="ps-widget ps-empty">No payment methods available</div>;
  }

  return (
    <div class="ps-widget">
      <div class="ps-methods">
        {methods.map((m) => (
          <label
            key={m.payment_method}
            class={`ps-method ${selectedMethod === m.payment_method ? 'ps-method--selected' : ''}`}
          >
            <input
              type="radio"
              name="ps-payment-method"
              value={m.payment_method}
              checked={selectedMethod === m.payment_method}
              onChange={() => onMethodChange(m.payment_method)}
            />
            <span class="ps-method-icon">{METHOD_ICONS[m.payment_method] ?? '💰'}</span>
            <span class="ps-method-label">{METHOD_LABELS[m.payment_method] ?? m.payment_method}</span>
          </label>
        ))}
      </div>
    </div>
  );
}

export function renderWidget(
  container: HTMLElement,
  props: PaymentWidgetProps,
): void {
  preactRender(h(PaymentWidgetUI, props), container);
}

export function unmountWidget(container: HTMLElement): void {
  preactRender(null, container);
}
```

**Step 2: Create minimal styles**

```css
/* widget/src/ui/styles.css */
.ps-widget {
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
  font-size: 14px;
  color: #1a1a1a;
}

.ps-methods {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.ps-method {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 16px;
  border: 1px solid #e0e0e0;
  border-radius: 8px;
  cursor: pointer;
  transition: border-color 0.15s, background-color 0.15s;
}

.ps-method:hover {
  border-color: #999;
}

.ps-method--selected {
  border-color: #0066ff;
  background-color: #f0f7ff;
}

.ps-method input[type="radio"] {
  display: none;
}

.ps-method-icon {
  font-size: 20px;
}

.ps-method-label {
  font-weight: 500;
}

.ps-loading, .ps-error, .ps-empty {
  padding: 24px;
  text-align: center;
  color: #666;
}

.ps-error {
  color: #dc2626;
}

.ps-spinner {
  width: 24px;
  height: 24px;
  margin: 0 auto;
  border: 3px solid #e0e0e0;
  border-top-color: #0066ff;
  border-radius: 50%;
  animation: ps-spin 0.6s linear infinite;
}

@keyframes ps-spin {
  to { transform: rotate(360deg); }
}
```

**Step 3: Update payswitch.ts to use Preact render**

Replace the `render()` and `mount()` methods in `PaymentWidgetImpl` to use `renderWidget` and `unmountWidget` from the Preact component instead of raw innerHTML. Import the CSS at the top of `payswitch.ts`:

```ts
import { renderWidget, unmountWidget } from './ui/PaymentWidget';
import './ui/styles.css';
```

Replace the `render()` method body:
```ts
private render(): void {
  if (!this.container) return;
  renderWidget(this.container, {
    methods: this.methods,
    selectedMethod: this.collection.selectedMethod,
    onMethodChange: (method) => {
      this.collection.selectedMethod = method;
      this.emit('change', { paymentMethod: method });
    },
  });
}
```

Replace `unmount()`:
```ts
unmount(): void {
  if (this.container) {
    unmountWidget(this.container);
  }
}
```

**Step 4: Build and verify**

Run: `cd widget && npm run build`
Expected: Build succeeds, CSS is bundled into the JS output

**Step 5: Commit**

```bash
git add widget/src/ui/ widget/src/payswitch.ts
git commit -m "feat: Preact-based payment method selector UI"
```

---

## Task 8: E2E Test — Full Payment Flow

**Files:**
- Create: `widget/e2e/payment-flow.spec.ts` (or add to dashboard Playwright)
- Create: `widget/demo.html` (test harness page)

**Step 1: Create demo.html for manual testing and E2E**

```html
<!-- widget/demo.html -->
<!DOCTYPE html>
<html>
<head>
  <title>Payswitch Widget Demo</title>
  <meta charset="utf-8" />
  <style>
    body { font-family: sans-serif; max-width: 480px; margin: 40px auto; }
    #payment-element { margin: 20px 0; }
    #pay-btn { padding: 12px 24px; background: #0066ff; color: #fff; border: none; border-radius: 8px; cursor: pointer; font-size: 16px; width: 100%; }
    #pay-btn:disabled { opacity: 0.5; }
    #result { margin-top: 20px; padding: 16px; border-radius: 8px; display: none; }
    #result.success { display: block; background: #f0fdf4; border: 1px solid #86efac; }
    #result.error { display: block; background: #fef2f2; border: 1px solid #fca5a5; }
  </style>
</head>
<body>
  <h2>Payment Demo</h2>
  <div id="payment-element"></div>
  <button id="pay-btn" disabled>Pay</button>
  <div id="result"></div>

  <script type="module">
    import { loadPayswitch } from './dist/payswitch.mjs';

    const params = new URLSearchParams(location.search);
    const clientSecret = params.get('clientSecret');
    const publishableKey = params.get('publishableKey');
    const backendUrl = params.get('backendUrl') || 'http://payswitch.test';

    if (!clientSecret || !publishableKey) {
      document.getElementById('result').textContent = 'Missing clientSecret or publishableKey in URL params';
      document.getElementById('result').className = 'error';
    }

    const ps = await loadPayswitch(publishableKey, { customBackendUrl: backendUrl });
    const widgets = ps.widgets({ clientSecret });
    const payment = widgets.create('payment');

    payment.mount('#payment-element');
    payment.on('ready', () => {
      document.getElementById('pay-btn').disabled = false;
    });

    document.getElementById('pay-btn').addEventListener('click', async () => {
      const btn = document.getElementById('pay-btn');
      btn.disabled = true;
      btn.textContent = 'Processing...';

      const result = await ps.confirmPayment({
        widgets,
        confirmParams: { return_url: location.href },
      });

      const el = document.getElementById('result');
      if (result.status === 'requires_customer_action') {
        el.textContent = 'Redirecting to payment provider...';
        el.className = 'success';
        // In real flow: window.location.href = result.redirectUrl
      } else if (result.status === 'succeeded') {
        el.textContent = 'Payment succeeded!';
        el.className = 'success';
      } else {
        el.textContent = `Error: ${result.error?.message}`;
        el.className = 'error';
      }

      btn.disabled = false;
      btn.textContent = 'Pay';
    });
  </script>
</body>
</html>
```

**Step 2: Write Playwright E2E test**

Add to the dashboard's Playwright tests or create a separate config in `/widget/`:

```ts
// dashboard/e2e/widget-payment-flow.spec.ts
import { test, expect } from '@playwright/test';

test.describe('Payment Widget E2E', () => {
  test('full redirect payment flow via widget', async ({ request, page }) => {
    // 1. Create payment via API (server-side, using secret key)
    const apiKey = process.env.TEST_SECRET_KEY;
    const publishableKey = process.env.TEST_PUBLISHABLE_KEY;
    const backendUrl = process.env.BACKEND_URL ?? 'http://payswitch.test';

    const createRes = await request.post(`${backendUrl}/api/v1/payments`, {
      headers: {
        'api-key': apiKey!,
        'Content-Type': 'application/vnd.api+json',
        'Accept': 'application/vnd.api+json',
      },
      data: {
        amount: 10000,
        currency: 'RUB',
        return_url: 'https://example.com/return',
      },
    });
    expect(createRes.ok()).toBeTruthy();
    const payment = await createRes.json();
    const clientSecret = payment.data.attributes.client_secret;

    // 2. Open demo page with widget
    await page.goto(
      `http://localhost:5173/demo.html?clientSecret=${clientSecret}&publishableKey=${publishableKey}&backendUrl=${backendUrl}`,
    );

    // 3. Wait for widget to be ready
    await expect(page.locator('.ps-widget .ps-method')).toBeVisible({ timeout: 10000 });
    await expect(page.locator('#pay-btn')).toBeEnabled();

    // 4. Select payment method (card should be auto-selected)
    await expect(page.locator('.ps-method--selected')).toContainText('Card');

    // 5. Click pay
    await page.click('#pay-btn');

    // 6. Verify redirect result
    await expect(page.locator('#result')).toContainText('Redirecting');
  });
});
```

**Step 3: Commit**

```bash
git add widget/demo.html dashboard/e2e/widget-payment-flow.spec.ts
git commit -m "test: E2E payment flow via widget + demo page"
```

---

## Task 9: Dashboard Integration

**Files:**
- Modify: `dashboard/src/pages/test-payment.tsx` (embed widget)
- Modify: `dashboard/package.json` (add widget as local dep or import from build)

**This task should be done after Tasks 1-7 are working.** The approach depends on how the widget is served:

**Option A — Import built widget in dashboard:**

Add to `dashboard/package.json`:
```json
"@payswitch/js": "file:../widget"
```

Then in `test-payment.tsx`, add a section that uses the widget:

```tsx
import { loadPayswitch } from '@payswitch/js';
import { useEffect, useRef } from 'react';

function WidgetTestSection({ clientSecret }: { clientSecret: string }) {
  const containerRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!clientSecret || !containerRef.current) return;

    let widget: any;
    const init = async () => {
      const ps = await loadPayswitch(merchant.publishableKey, {
        customBackendUrl: '', // same origin, proxied by Vite
      });
      const widgets = ps.widgets({ clientSecret });
      widget = widgets.create('payment');
      widget.mount(containerRef.current!);
    };
    init();

    return () => widget?.destroy();
  }, [clientSecret]);

  return <div ref={containerRef} />;
}
```

**Option B — Script tag approach (like a real merchant):**

Load the widget as a `<script>` in the test page to simulate real integration.

**The exact implementation depends on how you want to structure the dev experience. Option A is faster for development, Option B is closer to production.**

**Step 1: Commit**

```bash
git add dashboard/src/pages/test-payment.tsx dashboard/package.json
git commit -m "feat: integrate payment widget in dashboard test-payment page"
```

---

## Execution Order

| Order | Task | Dependencies | Estimated Complexity |
|-------|------|-------------|---------------------|
| 1 | AuthenticateClientSecret Middleware | None | Small |
| 2 | PublicPaymentController + Routes | Task 1 | Medium |
| 3 | CORS for Public API | Task 2 | Small |
| 4 | Widget Package Scaffold | None (parallel with 1-3) | Small |
| 5 | API Client | Task 4 | Small |
| 6 | SDK Loader | Task 5 | Medium |
| 7 | Widget UI (Preact) | Task 6 | Medium |
| 8 | E2E Test | Tasks 1-7 | Medium |
| 9 | Dashboard Integration | Tasks 1-7 | Small |

**Parallelizable:** Tasks 1-3 (backend) can run in parallel with Tasks 4-7 (frontend).
