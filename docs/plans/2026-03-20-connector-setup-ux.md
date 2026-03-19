# Connector Setup UX — Test Connection, Webhook URL, Setup Instructions

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Fix test connection 404, show saved credentials, display webhook URL and PSP setup instructions on connector detail page and after creation wizard.

**Architecture:** Add `testConnection()` to ConnectorInterface + all drivers (lightweight health-check per PSP). Return masked credentials + webhook_url in ConnectorResource. Add webhook URL card and PSP-specific setup instructions to frontend connector detail page and post-creation wizard step.

**Tech Stack:** PHP 8.3 / Laravel 13 / Pest (backend), React 19 / TypeScript / TanStack Query (frontend)

---

### Task 1: Add `testConnection()` to ConnectorInterface

**Files:**
- Modify: `packages/streeboga/payment-data/src/Contracts/ConnectorInterface.php`

**Step 1: Add method to interface**

Add after `extractPaymentIdFromWebhook`:

```php
/**
 * Test the connection to the PSP by performing a lightweight health check.
 *
 * @return array{success: bool, message: string}
 */
public function testConnection(): array;
```

**Step 2: Commit**

```bash
git add packages/streeboga/payment-data/src/Contracts/ConnectorInterface.php
git commit -m "feat: add testConnection to ConnectorInterface"
```

---

### Task 2: Implement `testConnection()` in all connector drivers

**Files:**
- Modify: `packages/streeboga/payment-connectors/src/Drivers/CloudPaymentsConnector.php`
- Modify: `packages/streeboga/payment-connectors/src/Drivers/StripeConnector.php`
- Modify: `packages/streeboga/payment-connectors/src/Drivers/YooKassaConnector.php`
- Modify: `packages/streeboga/payment-connectors/src/Drivers/TestConnector.php`

**Step 1: Implement in CloudPaymentsConnector**

Add method (uses CP's `/test` endpoint):

```php
public function testConnection(): array
{
    try {
        $response = Http::withBasicAuth($this->publicId, $this->apiSecret)
            ->timeout(10)
            ->post($this->baseUrl . '/test');

        $body = $response->json();
        $success = ($body['Success'] ?? false) === true;

        return [
            'success' => $success,
            'message' => $success ? 'Connection successful' : ($body['Message'] ?? 'Authentication failed'),
        ];
    } catch (\Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
```

**Step 2: Implement in StripeConnector**

Add method (GET `/v1/balance` — lightest authenticated endpoint):

```php
public function testConnection(): array
{
    try {
        $response = Http::withToken($this->apiKey)
            ->timeout(10)
            ->get($this->baseUrl . '/balance');

        if ($response->successful()) {
            return ['success' => true, 'message' => 'Connection successful'];
        }

        $body = $response->json();
        return [
            'success' => false,
            'message' => $body['error']['message'] ?? 'Authentication failed',
        ];
    } catch (\Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
```

**Step 3: Implement in YooKassaConnector**

Read `YooKassaConnector.php` to find the correct auth pattern (Basic Auth with shopId/secretKey), then add:

```php
public function testConnection(): array
{
    try {
        $response = Http::withBasicAuth($this->shopId, $this->secretKey)
            ->timeout(10)
            ->get($this->baseUrl . '/me');

        if ($response->successful()) {
            return ['success' => true, 'message' => 'Connection successful'];
        }

        $body = $response->json();
        return [
            'success' => false,
            'message' => $body['description'] ?? 'Authentication failed',
        ];
    } catch (\Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
```

**Step 4: Implement in TestConnector**

```php
public function testConnection(): array
{
    return ['success' => true, 'message' => 'Test connector is always available'];
}
```

**Step 5: Commit**

```bash
git add packages/streeboga/payment-connectors/src/Drivers/
git commit -m "feat: implement testConnection in all connector drivers"
```

---

### Task 3: Add test connection route and controller method

**Files:**
- Modify: `routes/api.php` (line ~109, after connector CRUD routes)
- Modify: `app/Http/Controllers/Dashboard/DashboardConnectorController.php`
- Modify: `app/Services/ConnectorService.php`

**Step 1: Add `testConnection` to ConnectorService**

```php
/**
 * @return array{success: bool, message: string}
 */
public function testConnection(string $merchantKey, string $connectorKey): array
{
    $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);
    $connector = $this->merchantRepository->findConnectorByMerchantAndKey($merchant->id, $connectorKey);

    $driver = \Streeboga\PaymentConnectors\ConnectorFactory::resolve($connector);

    return $driver->testConnection();
}
```

**Step 2: Add controller method**

Add to `DashboardConnectorController`:

```php
use Dedoc\Scramble\Attributes\Response as ApiResponse;

/**
 * Test connector connection
 *
 * Verify that the connector credentials are valid by performing a health check against the PSP.
 */
#[PathParameter('connectorKey', description: 'Connector public key', example: 'mca_01jd5x7k3m9p2q4r6s8t0v')]
#[ApiResponse(200, description: 'Test result')]
#[ApiResponse(404, description: 'Connector not found')]
public function testConnection(string $connectorKey, Request $request): JsonResponse
{
    $merchantId = $request->attributes->get('merchant_id');
    Gate::authorize('connector.view', [$merchantId]);
    $merchantKey = $request->attributes->get('merchant_key');

    $result = $this->connectorService->testConnection($merchantKey, $connectorKey);

    return response()->json(['data' => $result]);
}
```

**Step 3: Add route**

In `routes/api.php`, after line 109 (connector destroy route), add:

```php
Route::post('/connectors/{connectorKey}/test', [DashboardConnectorController::class, 'testConnection']);
```

**Step 4: Run tests**

```bash
./vendor/bin/pest tests/Feature/Api/ --filter=Connector
```

**Step 5: Commit**

```bash
git add routes/api.php app/Http/Controllers/Dashboard/DashboardConnectorController.php app/Services/ConnectorService.php
git commit -m "feat: add POST /connectors/{key}/test endpoint for connection health check"
```

---

### Task 4: Return masked credentials and webhook info in ConnectorResource

**Files:**
- Modify: `app/Http/Resources/ConnectorResource.php`

**Step 1: Update `toAttributes` to include masked credentials and webhook URL**

The resource has access to `$this->resource` which is the `MerchantConnectorAccount` model. We need to:
- Return `connector_account_details` with masked values (show first 4 chars, mask the rest)
- Return `webhook_url` constructed from merchant key + connector key

```php
/** @return array<string, mixed> */
public function toAttributes(Request $request): array
{
    return [
        'connector_name' => $this->connector_name,
        'connector_type' => $this->connector_type,
        'connector_account_details' => $this->maskedCredentials(),
        'payment_methods_enabled' => $this->payment_methods_enabled,
        'test_mode' => $this->test_mode,
        'disabled' => $this->disabled,
        'webhook_url' => $this->buildWebhookUrl(),
        'created_at' => $this->created_at->toIso8601String(),
    ];
}

/** @return array<string, string> */
private function maskedCredentials(): array
{
    $details = $this->connector_account_details ?? [];
    $masked = [];

    foreach ($details as $key => $value) {
        if (! is_string($value) || $value === '') {
            $masked[$key] = '';
            continue;
        }
        $masked[$key] = mb_strlen($value) > 8
            ? mb_substr($value, 0, 4) . str_repeat('*', mb_strlen($value) - 4)
            : str_repeat('*', mb_strlen($value));
    }

    return $masked;
}

private function buildWebhookUrl(): string
{
    $merchantKey = $this->merchantAccount?->key ?? '';
    $baseUrl = rtrim(config('app.url'), '/');

    return "{$baseUrl}/api/v1/webhooks/{$merchantKey}/{$this->key}";
}
```

Note: check that the `MerchantConnectorAccount` model has a `merchantAccount` relationship. If not, load the merchant key from the relation `merchant_account_id` → `MerchantAccount`.

**Step 2: Run existing tests**

```bash
./vendor/bin/pest tests/Feature/Api/ --filter=Connector
```

**Step 3: Commit**

```bash
git add app/Http/Resources/ConnectorResource.php
git commit -m "feat: return masked credentials and webhook URL in ConnectorResource"
```

---

### Task 5: Update frontend types and API layer

**Files:**
- Modify: `dashboard/src/api/types/entities.ts`

**Step 1: Add `connector_account_details` and `webhook_url` to ConnectorAttributes**

```typescript
export interface ConnectorAttributes {
  connector_name: ConnectorName
  connector_type: ConnectorType
  connector_account_details: Record<string, string> | null
  payment_methods_enabled: (
    | string
    | { payment_method: string; payment_method_types?: unknown[] }
  )[]
  test_mode: boolean
  disabled: boolean
  webhook_url: string
  created_at: string
}
```

**Step 2: Commit**

```bash
git add dashboard/src/api/types/entities.ts
git commit -m "feat: add connector_account_details and webhook_url to ConnectorAttributes"
```

---

### Task 6: Update connector detail page — show saved credentials, webhook URL, setup instructions

**Files:**
- Modify: `dashboard/src/pages/connector-detail.tsx`
- Modify: `dashboard/src/locales/ru.json`
- Modify: `dashboard/src/locales/en.json`

**Step 1: Add i18n keys**

Add to `connectorDetail` section in both `ru.json` and `en.json`:

**ru.json additions:**
```json
"webhookUrlTitle": "Webhook URL",
"webhookUrlDesc": "Укажите этот URL в настройках уведомлений вашей платёжной системы.",
"webhookUrlCopied": "URL скопирован",
"setupTitle": "Инструкция по настройке",
"setupStripe": "1. Откройте [Dashboard → Developers → Webhooks](https://dashboard.stripe.com/webhooks)\n2. Нажмите «Add endpoint»\n3. Вставьте Webhook URL выше\n4. Выберите события: `payment_intent.succeeded`, `payment_intent.payment_failed`, `payment_intent.canceled`, `payment_intent.requires_action`\n5. Скопируйте Signing Secret и вставьте его в поле API Secret выше",
"setupCloudpayments": "1. Откройте [Личный кабинет CloudPayments](https://merchant.cloudpayments.ru/) → Настройки сайта\n2. В разделе «Уведомления» (Pay, Fail, Confirm) укажите Webhook URL выше\n3. Формат: CloudPayments\n4. HTTP-метод: POST\n5. Кодировка: UTF-8",
"setupTest": "Тестовый коннектор не требует дополнительной настройки.",
"credentialsSaved": "Учётные данные сохранены. Оставьте поля пустыми, чтобы не менять."
```

**en.json additions:**
```json
"webhookUrlTitle": "Webhook URL",
"webhookUrlDesc": "Set this URL in your payment provider's webhook/notification settings.",
"webhookUrlCopied": "URL copied",
"setupTitle": "Setup Instructions",
"setupStripe": "1. Open [Dashboard → Developers → Webhooks](https://dashboard.stripe.com/webhooks)\n2. Click «Add endpoint»\n3. Paste the Webhook URL above\n4. Select events: `payment_intent.succeeded`, `payment_intent.payment_failed`, `payment_intent.canceled`, `payment_intent.requires_action`\n5. Copy the Signing Secret and paste it into the API Secret field above",
"setupCloudpayments": "1. Open [CloudPayments Dashboard](https://merchant.cloudpayments.ru/) → Site Settings\n2. In «Notifications» (Pay, Fail, Confirm) set the Webhook URL above\n3. Format: CloudPayments\n4. HTTP method: POST\n5. Encoding: UTF-8",
"setupTest": "Test connector requires no additional setup.",
"credentialsSaved": "Credentials saved. Leave fields empty to keep current values."
```

**Step 2: Update connector-detail.tsx**

Add clipboard copy, webhook URL card, setup instructions card, and show masked credential placeholders.

Key changes to `ConnectorDetailForm`:
1. Import `Copy, CheckCheck, BookOpen, Link` from lucide-react
2. Use `connector.connector_account_details` as placeholders for credential fields (the masked values)
3. Add Webhook URL card with copy button
4. Add Setup Instructions card with markdown-like content per connector

After the Credentials Card, add:

```tsx
{/* Webhook URL Section */}
<Card>
  <CardHeader>
    <CardTitle className="flex items-center gap-2">
      <Link className="h-4 w-4" />
      {t('connectorDetail.webhookUrlTitle')}
    </CardTitle>
    <CardDescription>{t('connectorDetail.webhookUrlDesc')}</CardDescription>
  </CardHeader>
  <CardContent>
    <div className="flex items-center gap-2">
      <Input
        readOnly
        value={connector.webhook_url}
        className="font-mono text-sm"
      />
      <Button
        type="button"
        variant="outline"
        size="icon"
        onClick={() => {
          void navigator.clipboard.writeText(connector.webhook_url)
          setCopied(true)
          setTimeout(() => setCopied(false), 2000)
        }}
      >
        {copied ? <CheckCheck className="h-4 w-4 text-emerald-600" /> : <Copy className="h-4 w-4" />}
      </Button>
    </div>
  </CardContent>
</Card>

{/* Setup Instructions Section */}
<Card>
  <CardHeader>
    <CardTitle className="flex items-center gap-2">
      <BookOpen className="h-4 w-4" />
      {t('connectorDetail.setupTitle')}
    </CardTitle>
  </CardHeader>
  <CardContent>
    <div className="text-muted-foreground prose-sm whitespace-pre-line text-sm">
      {t(`connectorDetail.setup${connectorName.charAt(0).toUpperCase() + connectorName.slice(1)}`)}
    </div>
  </CardContent>
</Card>
```

For credentials: show the hint that credentials are saved + use masked values as placeholders:

```tsx
{connector.connector_account_details && Object.keys(connector.connector_account_details).length > 0 && (
  <p className="text-muted-foreground text-xs">
    {t('connectorDetail.credentialsSaved')}
  </p>
)}
```

And update each credential Input to use masked value as placeholder:

```tsx
placeholder={
  connector.connector_account_details?.[field.key]
    ? connector.connector_account_details[field.key]
    : field.placeholder
}
```

**Step 3: Commit**

```bash
git add dashboard/src/pages/connector-detail.tsx dashboard/src/locales/ru.json dashboard/src/locales/en.json
git commit -m "feat: show webhook URL, setup instructions, and saved credentials on connector detail page"
```

---

### Task 7: Add post-creation success step to ConnectWizard

**Files:**
- Modify: `dashboard/src/components/connectors/connect-wizard.tsx`
- Modify: `dashboard/src/locales/ru.json`
- Modify: `dashboard/src/locales/en.json`

**Step 1: Add i18n keys**

**ru.json** additions to `connectWizard`:
```json
"step5": "Готово!",
"successTitle": "Коннектор подключён",
"successDesc": "Настройте уведомления в платёжной системе, чтобы получать обновления о статусе платежей.",
"webhookUrlLabel": "Webhook URL",
"goToConnector": "Перейти к настройкам",
"closeButton": "Закрыть"
```

**en.json** additions:
```json
"step5": "Done!",
"successTitle": "Connector connected",
"successDesc": "Configure notifications in your payment provider to receive payment status updates.",
"webhookUrlLabel": "Webhook URL",
"goToConnector": "Go to settings",
"closeButton": "Close"
```

**Step 2: Add Step 5 to the wizard**

Change `TOTAL_STEPS` from 4 to 5. Add step 5 title key. After successful creation, move to step 5 instead of closing the dialog. Step 5 shows:
- Success message
- Webhook URL with copy button
- "Go to connector" button that navigates to connector detail page
- "Close" button

Store the created connector response (it includes `webhook_url` and `id`) in state:

```tsx
const [createdConnector, setCreatedConnector] = useState<{id: string; webhook_url: string} | null>(null)
```

In `handleFinish`, on success:
```tsx
onSuccess: (data) => {
  setCreatedConnector({ id: data.id, webhook_url: data.webhook_url })
  setStep(5)
}
```

Step 5 component:
```tsx
function Step5Success({
  webhookUrl,
  connectorId,
  onGoToConnector,
}: {
  webhookUrl: string
  connectorId: string
  onGoToConnector: () => void
}) {
  const { t } = useTranslation()
  const [copied, setCopied] = useState(false)

  return (
    <div className="space-y-4 text-center">
      <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-950">
        <Check className="h-6 w-6 text-emerald-600" />
      </div>
      <div>
        <h3 className="font-semibold">{t('connectWizard.successTitle')}</h3>
        <p className="text-muted-foreground text-sm">{t('connectWizard.successDesc')}</p>
      </div>
      <div className="space-y-2 text-left">
        <Label>{t('connectWizard.webhookUrlLabel')}</Label>
        <div className="flex items-center gap-2">
          <Input readOnly value={webhookUrl} className="font-mono text-xs" />
          <Button
            type="button"
            variant="outline"
            size="icon"
            onClick={() => {
              void navigator.clipboard.writeText(webhookUrl)
              setCopied(true)
              setTimeout(() => setCopied(false), 2000)
            }}
          >
            {copied ? <CheckCheck className="h-4 w-4 text-emerald-600" /> : <Copy className="h-4 w-4" />}
          </Button>
        </div>
      </div>
      <Button type="button" className="w-full" onClick={onGoToConnector}>
        {t('connectWizard.goToConnector')}
      </Button>
    </div>
  )
}
```

Update `ConnectWizardProps` to accept `onNavigateToConnector`:
```typescript
interface ConnectWizardProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onSuccess?: () => void
  onNavigateToConnector?: (connectorKey: string) => void
}
```

**Step 3: Update the connectors page to pass `onNavigateToConnector`**

In `connectors.tsx`, pass:
```tsx
<ConnectWizard
  open={wizardOpen}
  onOpenChange={setWizardOpen}
  onSuccess={() => void queryClient.invalidateQueries({ queryKey: ['connectors'] })}
  onNavigateToConnector={(key) => void navigate({ to: '/connectors/$connectorKey', params: { connectorKey: key } })}
/>
```

**Step 4: Commit**

```bash
git add dashboard/src/components/connectors/connect-wizard.tsx dashboard/src/pages/connectors.tsx dashboard/src/locales/ru.json dashboard/src/locales/en.json
git commit -m "feat: add post-creation success step with webhook URL to connect wizard"
```

---

### Task 8: Write backend test for test connection endpoint

**Files:**
- Create: `tests/Feature/Dashboard/ConnectorTestConnectionTest.php`

**Step 1: Write test**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    // Check existing test setup patterns — use the same helpers used in
    // tests/Feature/Api/ for creating merchant, org, user, auth
    // Look at tests/Feature/Dashboard/ for the dashboard test pattern
});

test('test connection returns success for valid credentials', function () {
    Http::fake([
        'api.cloudpayments.ru/test' => Http::response(['Success' => true]),
    ]);

    // Create connector with cloudpayments driver
    // POST to /api/v1/dashboard/connectors/{key}/test
    // Assert 200 with { data: { success: true, message: 'Connection successful' } }
});

test('test connection returns error for invalid credentials', function () {
    Http::fake([
        'api.cloudpayments.ru/test' => Http::response(['Success' => false, 'Message' => 'Invalid credentials'], 401),
    ]);

    // POST to /api/v1/dashboard/connectors/{key}/test
    // Assert 200 with { data: { success: false, message: ... } }
});

test('test connection returns 404 for unknown connector', function () {
    // POST to /api/v1/dashboard/connectors/mca_nonexistent/test
    // Assert 404
});
```

Adapt test setup to match existing patterns in `tests/Feature/Dashboard/` or `tests/Feature/Api/`. Read existing test files to understand the auth/setup helpers.

**Step 2: Run test**

```bash
./vendor/bin/pest tests/Feature/Dashboard/ConnectorTestConnectionTest.php
```

**Step 3: Commit**

```bash
git add tests/Feature/Dashboard/ConnectorTestConnectionTest.php
git commit -m "test: add tests for connector test connection endpoint"
```

---

### Task 9: Verify MerchantConnectorAccount → MerchantAccount relationship exists

**Files:**
- Possibly modify: `packages/streeboga/payment-data/src/Models/MerchantConnectorAccount.php`

**Step 1: Check model for `merchantAccount` relationship**

Read the model file. If `merchantAccount()` relationship does not exist, add:

```php
public function merchantAccount(): \Illuminate\Database\Eloquent\Relations\BelongsTo
{
    return $this->belongsTo(MerchantAccount::class, 'merchant_account_id');
}
```

**Step 2: Ensure ConnectorResource eager-loads the relation**

In `DashboardConnectorController::show()`, the connector is loaded via repository. Check if merchant relation needs eager loading. If `$this->merchantAccount` in ConnectorResource triggers N+1, add `->load('merchantAccount')` in the service or controller.

**Step 3: Commit if changes were needed**

```bash
git add packages/streeboga/payment-data/src/Models/MerchantConnectorAccount.php
git commit -m "feat: add merchantAccount relationship to MerchantConnectorAccount"
```

---

### Task 10: Run full test suite + lint

**Step 1: Run backend tests**

```bash
./vendor/bin/pest
```

**Step 2: Run frontend lint and type check**

```bash
cd dashboard && npm run lint:check && npm run format:check && npm run types:check
```

**Step 3: Fix any issues and commit**

```bash
composer ci:check
```
