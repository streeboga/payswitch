# Development Section Improvements

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Improve the "Development" section — extend seeders, enhance test payment form, fix event logs, hide test-only pages in live mode.

**Architecture:** Extend existing `payswitch:seed` command with payment/refund/event data. Modify test payment form to support amount in major currency units and connector selection. Add `testOnly` flag to nav items and route guards. Event Logs page maps to existing `payment_audit_log` + `webhook_events` UNION query — no new tables needed.

**Tech Stack:** Laravel 13, PHP 8.3, React 19, TypeScript, Zustand, TanStack Router, i18next

---

### Task 1: Extend `payswitch:seed` with payments, refunds, customers, routing rules, and event log data

**Files:**
- Modify: `app/Console/Commands/PayswitchSeedCommand.php`

**Step 1: Add customer, payment, refund, routing, and audit log seeding**

Add the following after the existing connector creation (after line ~107) in `PayswitchSeedCommand::handle()`:

```php
// 8. Customers
$customers = [];
$customerData = [
    ['name' => 'Иван Петров', 'email' => 'ivan@example.com', 'phone' => '+79991234567'],
    ['name' => 'Anna Smith', 'email' => 'anna@example.com', 'phone' => '+15551234567'],
    ['name' => 'Demo Customer', 'email' => 'demo@example.com', 'phone' => null],
];
foreach ($customerData as $cd) {
    $customers[] = \Streeboga\PaymentData\Models\Customer::create([
        'merchant_account_id' => $merchant->id,
        'name' => $cd['name'],
        'email' => $cd['email'],
        'phone' => $cd['phone'],
    ]);
}
$this->line("Customers: <info>" . count($customers) . " created</info>");

// 9. Routing Rule (priority: test connector first)
$testConnectorMca = \Streeboga\PaymentData\Models\MerchantConnectorAccount::where('merchant_account_id', $merchant->id)
    ->where('connector_name', 'test')
    ->first();

\Streeboga\PaymentData\Models\RoutingRule::create([
    'merchant_account_id' => $merchant->id,
    'business_profile_id' => $profile->id,
    'type' => 'priority',
    'name' => 'Default Priority',
    'rules' => ['connectors' => ['test', 'stripe']],
    'active' => true,
    'priority' => 10,
]);
$this->line('Routing Rule: <info>created (priority: test → stripe)</info>');

// 10. Payments with variety
$statuses = [
    ['status' => \Streeboga\PaymentData\Enums\PaymentStatus::Succeeded, 'count' => 6],
    ['status' => \Streeboga\PaymentData\Enums\PaymentStatus::Failed, 'count' => 3],
    ['status' => \Streeboga\PaymentData\Enums\PaymentStatus::Processing, 'count' => 2],
    ['status' => \Streeboga\PaymentData\Enums\PaymentStatus::Cancelled, 'count' => 2],
    ['status' => \Streeboga\PaymentData\Enums\PaymentStatus::RequiresCapture, 'count' => 1],
];
$currencies = ['RUB', 'USD', 'EUR'];
$captureMethods = [\Streeboga\PaymentData\Enums\CaptureMethod::Automatic, \Streeboga\PaymentData\Enums\CaptureMethod::Manual];
$payments = [];
$paymentIndex = 0;

foreach ($statuses as $statusGroup) {
    for ($i = 0; $i < $statusGroup['count']; $i++) {
        $currency = $currencies[$paymentIndex % count($currencies)];
        $amount = rand(1000, 500000);
        $captureMethod = $captureMethods[$paymentIndex % count($captureMethods)];
        $customer = $customers[$paymentIndex % count($customers)];
        $createdAt = now()->subHours(rand(1, 720));

        $payment = \Streeboga\PaymentData\Models\PaymentIntent::create([
            'merchant_account_id' => $merchant->id,
            'business_profile_id' => $profile->id,
            'amount' => $amount,
            'net_amount' => $amount,
            'amount_capturable' => $statusGroup['status'] === \Streeboga\PaymentData\Enums\PaymentStatus::RequiresCapture ? $amount : 0,
            'amount_received' => $statusGroup['status'] === \Streeboga\PaymentData\Enums\PaymentStatus::Succeeded ? $amount : 0,
            'currency' => $currency,
            'status' => $statusGroup['status'],
            'capture_method' => $captureMethod,
            'customer_id' => $customer->id,
            'connector' => 'test',
            'description' => "Demo payment #{$paymentIndex}",
            'metadata' => ['seeded' => true],
            'attempt_count' => 1,
            'session_expiry' => 900,
            'expires_on' => $createdAt->copy()->addMinutes(15),
            'error_code' => $statusGroup['status'] === \Streeboga\PaymentData\Enums\PaymentStatus::Failed ? 'card_declined' : null,
            'error_message' => $statusGroup['status'] === \Streeboga\PaymentData\Enums\PaymentStatus::Failed ? 'Your card was declined' : null,
        ]);
        $payment->created_at = $createdAt;
        $payment->save();

        // Payment attempt
        $attemptStatus = match($statusGroup['status']) {
            \Streeboga\PaymentData\Enums\PaymentStatus::Succeeded, \Streeboga\PaymentData\Enums\PaymentStatus::RequiresCapture => 'succeeded',
            \Streeboga\PaymentData\Enums\PaymentStatus::Failed => 'failed',
            default => 'processing',
        };
        \Streeboga\PaymentData\Models\PaymentAttempt::create([
            'payment_intent_id' => $payment->id,
            'connector' => 'test',
            'connector_transaction_id' => 'txn_test_' . \Illuminate\Support\Str::random(16),
            'status' => $attemptStatus,
            'amount' => $amount,
            'error_code' => $attemptStatus === 'failed' ? 'card_declined' : null,
            'error_message' => $attemptStatus === 'failed' ? 'Your card was declined' : null,
        ]);

        // Audit log entry (payment created)
        \Streeboga\PaymentData\Models\PaymentAuditLog::create([
            'payment_intent_id' => $payment->id,
            'merchant_account_id' => $merchant->id,
            'action' => 'payment.created',
            'previous_status' => null,
            'new_status' => 'requires_payment_method',
            'actor' => 'system',
            'metadata' => ['amount' => $amount, 'currency' => $currency],
            'created_at' => $createdAt,
        ]);

        // Audit log entry (status change)
        if ($statusGroup['status'] !== \Streeboga\PaymentData\Enums\PaymentStatus::Processing) {
            \Streeboga\PaymentData\Models\PaymentAuditLog::create([
                'payment_intent_id' => $payment->id,
                'merchant_account_id' => $merchant->id,
                'action' => 'payment.' . $statusGroup['status']->value,
                'previous_status' => 'requires_payment_method',
                'new_status' => $statusGroup['status']->value,
                'actor' => 'connector:test',
                'metadata' => null,
                'created_at' => $createdAt->copy()->addSeconds(rand(1, 30)),
            ]);
        }

        $payments[] = $payment;
        $paymentIndex++;
    }
}
$this->line("Payments: <info>" . count($payments) . " created</info>");

// 11. Refunds (on first 3 succeeded payments)
$succeededPayments = collect($payments)->filter(fn ($p) => $p->status === \Streeboga\PaymentData\Enums\PaymentStatus::Succeeded)->take(3)->values();
$refundStatuses = ['succeeded', 'failed', 'processing'];

foreach ($succeededPayments as $idx => $payment) {
    $refundAmount = intdiv($payment->amount, 2);
    \Streeboga\PaymentData\Models\Refund::create([
        'payment_intent_id' => $payment->id,
        'merchant_account_id' => $merchant->id,
        'business_profile_id' => $profile->id,
        'amount' => $refundAmount,
        'currency' => $payment->currency,
        'status' => $refundStatuses[$idx],
        'reason' => ['requested_by_customer', 'duplicate', 'fraudulent'][$idx],
        'connector' => 'test',
        'connector_refund_id' => 'ref_test_' . \Illuminate\Support\Str::random(16),
        'error_code' => $refundStatuses[$idx] === 'failed' ? 'refund_failed' : null,
        'error_message' => $refundStatuses[$idx] === 'failed' ? 'Refund could not be processed' : null,
    ]);
}
$this->line("Refunds: <info>3 created</info>");

// 12. Webhook events (for succeeded payments)
foreach ($succeededPayments as $payment) {
    \Streeboga\PaymentData\Models\WebhookEvent::create([
        'event_type' => 'payment.succeeded',
        'merchant_account_id' => $merchant->id,
        'business_profile_id' => $profile->id,
        'payment_intent_id' => $payment->id,
        'content' => ['payment_id' => $payment->key, 'status' => 'succeeded', 'amount' => $payment->amount],
        'delivered' => true,
        'delivery_attempts' => 1,
    ]);
}
$this->line("Webhook Events: <info>" . count($succeededPayments) . " created</info>");
```

**Step 2: Run and verify**

```bash
php artisan payswitch:seed --fresh --user=test@example.com
```

Expected: All entities created successfully, output shows counts.

**Step 3: Commit**

```bash
git add app/Console/Commands/PayswitchSeedCommand.php
git commit -m "feat: extend payswitch:seed with payments, refunds, customers, routing, audit logs, webhooks"
```

---

### Task 2: Improve test payment form — amount in major units, connector selector, more presets

**Files:**
- Modify: `dashboard/src/pages/test-payment.tsx`
- Modify: `dashboard/src/api/endpoints/dashboard-test-payment.ts`
- Modify: `dashboard/src/hooks/use-test-payment.ts`
- Modify: `dashboard/src/locales/en.json`
- Modify: `dashboard/src/locales/ru.json`

**Step 1: Update API endpoint type to include connector_name and description**

In `dashboard/src/api/endpoints/dashboard-test-payment.ts`, add `connector_name` and `description` to `TestPaymentRequest`:

```typescript
export interface TestPaymentRequest {
  amount: number
  currency: string
  payment_method: string
  card_number: string
  card_exp_month: string
  card_exp_year: string
  card_cvc: string
  capture_method: string
  connector_name?: string
  description?: string
}
```

**Step 2: Update the test payment page**

Replace the entire `test-payment.tsx` with the improved version that:
- Adds amount input in major units (rubles/dollars/euros) with ×100 conversion before send
- Default `100.00` instead of `10000`
- Shows currency symbol in amount label dynamically
- Adds connector selector using `useConnectorsList()` hook (filtered to non-disabled)
- Adds description text field
- Adds 2 more card presets: "Insufficient Funds" (`4000000000009995`), "Expired Card" (`4000000000000069`)
- Passes `connector_name` from selected connector to API

Key changes in the form:
```tsx
// Amount conversion: user enters 100.50, send 10050
const amountMinor = Math.round(parseFloat(amount) * 100)

// Connector selector
const { data: connectors } = useConnectorsList()
const activeConnectors = connectors?.filter(c => !c.disabled) ?? []

// New presets
const CARD_PRESETS: CardPreset[] = [
  { labelKey: 'testPayment.presetSuccessful', number: '4242424242424242' },
  { labelKey: 'testPayment.presetDecline', number: '4000000000000002' },
  { labelKey: 'testPayment.preset3ds', number: '4000000000003220' },
  { labelKey: 'testPayment.presetInsufficientFunds', number: '4000000000009995' },
  { labelKey: 'testPayment.presetExpiredCard', number: '4000000000000069' },
]
```

**Step 3: Add translations**

In both `en.json` and `ru.json` under `testPayment`, add:
```json
"presetInsufficientFunds": "Insufficient Funds" / "Недостаточно средств",
"presetExpiredCard": "Истёк срок" / "Expired Card",
"labelConnector": "Connector" / "Коннектор",
"connectorAuto": "Auto (routing)" / "Авто (роутинг)",
"labelDescription": "Description" / "Описание",
"descriptionPlaceholder": "Test payment" / "Тестовый платёж",
"amountHint": "in {{currency}}" / "в {{currency}}"
```

**Step 4: Commit**

```bash
git add dashboard/src/pages/test-payment.tsx dashboard/src/api/endpoints/dashboard-test-payment.ts dashboard/src/locales/en.json dashboard/src/locales/ru.json
git commit -m "feat: improve test payment form — major units, connector selector, more presets"
```

---

### Task 3: Backend — accept connector_name and description in test payment

**Files:**
- Modify: `app/Http/Requests/Dashboard/StoreTestPaymentRequest.php`
- Modify: `app/Services/TestPaymentService.php`

**Step 1: Add validation rules**

In `StoreTestPaymentRequest::rules()`, add:
```php
'connector_name' => 'sometimes|string',
'description' => 'sometimes|string|max:500',
```

**Step 2: Pass connector and description to payment creation**

In `TestPaymentService::createAndConfirm()`, modify the DTO creation:

```php
$dto = CreatePaymentData::from([
    'amount' => $params['amount'],
    'currency' => $params['currency'] ?? 'USD',
    'capture_method' => CaptureMethod::from($params['capture_method'] ?? 'automatic'),
    'confirm' => true,
    'payment_method' => $params['payment_method'] ?? 'card',
    'description' => $params['description'] ?? null,
    'payment_method_data' => $params['payment_method_data'] ?? [
        'card' => [
            'card_number' => $params['card_number'] ?? '4242424242424242',
            'card_exp_month' => $params['card_exp_month'] ?? '12',
            'card_exp_year' => $params['card_exp_year'] ?? '2030',
            'card_cvc' => $params['card_cvc'] ?? '123',
        ],
    ],
]);

$payment = $this->paymentService->create($dto, $merchantAccountId);

// Auto-confirm with optional connector override
$confirmDto = ConfirmPaymentData::from([
    'payment_method' => $dto->payment_method ?? 'card',
    'payment_method_data' => $dto->payment_method_data ?? [],
    'connector' => $params['connector_name'] ?? null,
]);

return $this->paymentService->confirm($payment->key, $confirmDto, $merchantAccountId);
```

Add inject `PaymentService` (already injected). Also need to add `use App\DataTransferObjects\Payment\ConfirmPaymentData;` import.

**Step 3: Commit**

```bash
git add app/Http/Requests/Dashboard/StoreTestPaymentRequest.php app/Services/TestPaymentService.php
git commit -m "feat: test payment accepts connector_name and description, properly confirms payment"
```

---

### Task 4: Fix Event Logs page — align frontend filters with backend API

**Files:**
- Modify: `dashboard/src/pages/event-logs.tsx`
- Modify: `dashboard/src/api/endpoints/dashboard-event-logs.ts`
- Modify: `dashboard/src/hooks/use-event-logs.ts`

**Step 1: Align EventLogAttributes with actual API response**

The backend returns: `event_type`, `action`, `resource_id`, `status`, `detail`, `created_at`.
The frontend expects: `event_type`, `resource_type`, `resource_id`, `description`, `connector`, `metadata`, `created_at`.

Update `EventLogAttributes` to match backend:
```typescript
export interface EventLogAttributes {
  event_type: string  // 'webhook' | 'status_change'
  action: string
  resource_id: string
  status: string
  detail: string | null
  created_at: string
}
```

**Step 2: Align filter params with backend API**

Backend accepts: `filter[type]`, `filter[from]`, `filter[to]`, `page[size]`.
Frontend sends: `type`, `resource_type`, `from`, `to`, `search`, `sort`, `direction`, `page`, `per_page`.

Update `EventLogListParams`:
```typescript
export interface EventLogListParams {
  type?: string           // 'webhook' | 'status_change'
  from?: string
  to?: string
  page?: number
  per_page?: number
}
```

Update the API call to use correct query param format:
```typescript
export const dashboardEventLogs = {
  async list(params: EventLogListParams = {}): Promise<PaginatedResult<EventLogAttributes>> {
    const searchParams: Record<string, string> = {}
    if (params.type) searchParams['filter[type]'] = params.type
    if (params.from) searchParams['filter[from]'] = params.from
    if (params.to) searchParams['filter[to]'] = params.to
    if (params.page) searchParams['page[number]'] = String(params.page)
    if (params.per_page) searchParams['page[size]'] = String(params.per_page)

    const doc = await getCollection<EventLogAttributes>('dashboard/event-logs', { searchParams })
    return parseCollection(doc)
  },
}
```

**Step 3: Update table columns to match new attributes**

In `event-logs.tsx`, update columns:
- `event_type` → shows `action` (e.g., `payment.succeeded`, `payment.created`)
- Remove `resource_type` filter (not supported by backend)
- Remove `search` filter (not supported by backend)
- Change type filter options to `webhook` / `status_change`
- `description` column → shows `detail`
- Remove `connector` column (not in backend response)
- Add `status` column

**Step 4: Commit**

```bash
git add dashboard/src/pages/event-logs.tsx dashboard/src/api/endpoints/dashboard-event-logs.ts dashboard/src/hooks/use-event-logs.ts
git commit -m "fix: align event logs frontend with backend API — correct filters, columns, and params"
```

---

### Task 5: Hide Test Payment and Onboarding in live mode

**Files:**
- Modify: `dashboard/src/components/sidebar/nav-config.ts`
- Modify: `dashboard/src/components/sidebar/sidebar.tsx`
- Modify: `dashboard/src/app/router.tsx`

**Step 1: Add `testOnly` property to NavItem and mark items**

In `nav-config.ts`, add `testOnly?: boolean` to `NavItem` interface:
```typescript
export interface NavItem {
  label: string
  path: string
  icon: LucideIcon
  badge?: number
  adminOnly?: boolean
  testOnly?: boolean
}
```

Mark onboarding and testPayment items:
```typescript
{ label: t('sidebar.onboarding'), path: '/onboarding', icon: Rocket, testOnly: true },
{ label: t('sidebar.testPayment'), path: '/test-payment', icon: FlaskConical, testOnly: true },
{ label: t('sidebar.eventLogs'), path: '/event-logs', icon: ScrollText },
```

**Step 2: Filter by testMode in sidebar**

In `sidebar.tsx`, read `testMode` from context store and filter items:
```typescript
const testMode = useContextStore((s) => s.testMode)

const visibleGroups = useMemo(() => {
  return getNavGroups(t)
    .filter((group) => !group.adminOnly || isAdmin)
    .map((group) => ({
      ...group,
      items: group.items.filter((item) => !item.testOnly || testMode),
    }))
    .filter((group) => group.items.length > 0)
}, [t, isAdmin, testMode])
```

**Step 3: Add route guards for live mode**

In `router.tsx`, modify `testPaymentRoute` and `onboardingRoute` to redirect in live mode:

```typescript
function requireTestMode() {
  requireAuth()
  const { testMode } = useContextStore.getState()
  if (!testMode) {
    throw redirect({ to: '/overview' })
  }
}

const testPaymentRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/test-payment',
  component: TestPaymentPage,
  beforeLoad: requireTestMode,
})

const onboardingRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/onboarding',
  component: OnboardingPage,
  beforeLoad: requireTestMode,
})
```

**Step 4: Commit**

```bash
git add dashboard/src/components/sidebar/nav-config.ts dashboard/src/components/sidebar/sidebar.tsx dashboard/src/app/router.tsx
git commit -m "feat: hide test payment and onboarding in live mode, keep event logs visible"
```

---

### Task 6: Run tests and verify

**Step 1: Run backend tests**

```bash
./vendor/bin/pest
```

**Step 2: Run frontend checks**

```bash
cd dashboard && npm run types:check && npm run lint:check && npm run test
```

**Step 3: Fix any failures, commit**

```bash
git add -A
git commit -m "fix: resolve test and lint issues"
```

---

### Task 7: Final verification — run seed and test manually

**Step 1: Reset and seed**

```bash
php artisan migrate:fresh && php artisan payswitch:seed --user=test@example.com
```

**Step 2: Start dev server**

```bash
composer dev
```

**Step 3: Manual verification checklist**

- [ ] Login at localhost:3000, verify sidebar shows Development section in test mode
- [ ] Switch to live mode, verify Test Payment and Onboarding disappear, Event Logs stays
- [ ] Switch back to test mode
- [ ] Open Test Payment page — verify amount input shows `100.00`, connector selector visible
- [ ] Send test payment with default settings — verify result shows
- [ ] Select specific connector — verify payment uses it
- [ ] Test different card presets (Insufficient Funds, Expired Card)
- [ ] Open Event Logs — verify audit log entries from seeded data appear
- [ ] Filter event logs by type (webhook / status_change)
- [ ] Open Payments page — verify seeded payments with variety show up
- [ ] Open Refunds page — verify 3 refunds
- [ ] Open Customers page — verify 3 customers
