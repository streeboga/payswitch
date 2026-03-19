# Test Coverage 85% + Mutation Score 70% Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Поднять test coverage с 76.7% до 85% и mutation score бизнес-логики с 56% до 70%.

**Architecture:** Фаза 1 — написать тесты для непокрытых сервисов (0% coverage). Фаза 2 — усилить существующие тесты конкретными assertions для ловли мутаций. Каждый тест-файл добавляет `covers()` для трекинга mutation score.

**Tech Stack:** Pest PHP, RefreshDatabase, Factories, Event::fake(), Queue::fake(), Http::fake()

---

## Фаза 1: Покрытие непокрытых сервисов (0% → 85%+)

### Task 1: ConnectorHealthService + ConnectorHealthRepository

**Files:**
- Test: `tests/Feature/Dashboard/ConnectorHealthTest.php`
- Covers: `app/Services/ConnectorHealthService.php` (0%)
- Covers: `app/Repositories/Eloquent/ConnectorHealthRepository.php` (0%)
- Covers: `app/Http/Controllers/Dashboard/ConnectorHealthController.php` (0%)

**Context:**
- Read `app/Services/ConnectorHealthService.php` — understand methods
- Read `app/Http/Controllers/Dashboard/ConnectorHealthController.php` — understand endpoints
- Read `app/Repositories/Eloquent/ConnectorHealthRepository.php` — understand queries
- Use existing test patterns from `tests/Feature/Dashboard/PaymentListTest.php`

**Step 1:** Write tests covering:
- `GET /dashboard/connector-health` returns JSON:API response with health stats
- Health stats include success_rate, total_transactions, avg_response_time
- Scoped to current merchant
- Requires authentication (401)
- Each assertion checks **concrete values**, not just structure

**Step 2:** Run tests, verify they fail (endpoints exist but no test coverage)

**Step 3:** Fix any issues found during testing

**Step 4:** Run `XDEBUG_MODE=coverage ./vendor/bin/pest --coverage --path=app/Services/ConnectorHealthService.php`

**Step 5:** Commit: `test: add ConnectorHealthService coverage`

---

### Task 2: TestPaymentService

**Files:**
- Test: `tests/Feature/Dashboard/TestPaymentTest.php`
- Covers: `app/Services/TestPaymentService.php` (0%)
- Covers: `app/Http/Controllers/Dashboard/TestPaymentController.php`

**Context:**
- Read `app/Services/TestPaymentService.php` — understand createAndConfirm flow
- Read `app/Http/Controllers/Dashboard/TestPaymentController.php` — understand endpoint
- This service creates a test payment and confirms it — mock connector responses

**Step 1:** Write tests covering:
- `POST /dashboard/test-payment` creates payment with test connector
- Successful test payment returns payment with status succeeded
- Failed test payment (mock connector failure) returns proper error
- Requires authentication
- Check concrete amounts, currencies, connector name

**Step 2-5:** Same pattern — run, fix, verify coverage, commit

**Commit:** `test: add TestPaymentService coverage`

---

### Task 3: UserRoleService + UserRoleRepository

**Files:**
- Test: `tests/Feature/Dashboard/UserRoleTest.php`
- Covers: `app/Services/UserRoleService.php` (0%)
- Covers: `app/Repositories/Eloquent/UserRoleRepository.php` (0%)
- Covers: `app/Http/Controllers/Dashboard/UserRoleController.php`

**Context:**
- Read `app/Services/UserRoleService.php`
- Read `app/Http/Controllers/Dashboard/UserRoleController.php`

**Step 1:** Write tests covering:
- List user roles for organization
- Assign role to user
- Update user role
- Remove user role
- Cannot assign role without admin permission
- Requires authentication

**Commit:** `test: add UserRoleService coverage`

---

### Task 4: DisputeService (33% → 85%)

**Files:**
- Test: `tests/Feature/Dashboard/DisputeTest.php` (exists, extend)
- Covers: `app/Services/DisputeService.php` (33%)

**Context:**
- Read `app/Services/DisputeService.php` — find uncovered methods (createEvidence, update status)
- Read existing `tests/Feature/Dashboard/DisputeTest.php`

**Step 1:** Add tests for uncovered paths:
- Create dispute evidence (file upload)
- Update dispute status transitions
- List disputes with filters
- Dispute scoped to merchant

**Commit:** `test: extend DisputeService coverage to 85%`

---

### Task 5: TwoFactorAuthService (55% → 85%)

**Files:**
- Test: `tests/Feature/Auth/TwoFactorTest.php` (exists, extend)
- Covers: `app/Services/TwoFactorAuthService.php` (55%)
- Covers: `app/Concerns/HasTwoFactorAuthentication.php` (93%)

**Context:**
- Read `app/Services/TwoFactorAuthService.php` — find uncovered: enable, disable, generateRecoveryCodes
- Read existing `tests/Feature/Auth/TwoFactorTest.php`

**Step 1:** Add tests for:
- Enable 2FA — generates secret, returns QR data
- Confirm 2FA with valid code
- Confirm 2FA with invalid code → error
- Disable 2FA
- Generate recovery codes
- Use recovery code

**Commit:** `test: extend TwoFactorAuthService coverage to 85%`

---

### Task 6: DashboardConnectorController (60% → 85%)

**Files:**
- Test: `tests/Feature/Dashboard/ConnectorTest.php` (exists, extend)
- Covers: `app/Http/Controllers/Dashboard/DashboardConnectorController.php` (60%)
- Covers: `app/Services/ConnectorService.php` (81%)

**Context:**
- Read controller — find uncovered: update, delete, testConnection
- Read existing tests

**Step 1:** Add tests for:
- Update connector credentials
- Delete connector
- Test connection endpoint
- Enable/disable connector toggle
- Validation errors on update

**Commit:** `test: extend ConnectorController coverage to 85%`

---

### Task 7: AuditLogController export (54% → 85%)

**Files:**
- Test: `tests/Feature/Dashboard/AuditLogTest.php` (exists, extend)
- Covers: `app/Http/Controllers/Dashboard/AuditLogController.php` (54%)

**Context:**
- Uncovered: CSV export endpoint

**Step 1:** Add test for:
- `GET /dashboard/audit-log/export` returns CSV
- CSV has correct headers
- CSV rows match audit log entries

**Commit:** `test: add AuditLog export test`

---

## Фаза 2: Усиление тестов для mutation score (56% → 70%)

### Task 8: CustomerService — concrete assertions

**Files:**
- Modify: `tests/Feature/Dashboard/CustomerTest.php`
- Covers: `app/Services/CustomerService.php`

**Step 1:** Add `covers(CustomerService::class)` at top of test file

**Step 2:** Strengthen existing tests:
- Create: assert exact name, email, phone in DB (not just assertCreated)
- Update: assert old value changed to new value
- Delete: assert assertDatabaseMissing after delete
- Custom ID: test that custom ID is stored exactly
- Duplicate custom ID: test 409 conflict

**Commit:** `test: strengthen CustomerService mutation coverage`

---

### Task 9: RoutingRuleService — concrete assertions

**Files:**
- Modify: `tests/Feature/Dashboard/RoutingRuleTest.php`
- Covers: `app/Services/RoutingRuleService.php`

**Step 1:** Add `covers(RoutingRuleService::class)`

**Step 2:** Strengthen:
- Create: assert type, name, priority, active, rules JSON stored correctly
- Update: assert specific fields changed
- Business profile resolution: test profile key → id mapping
- Delete: assertDatabaseMissing

**Commit:** `test: strengthen RoutingRuleService mutation coverage`

---

### Task 10: PaymentService — boundary conditions

**Files:**
- Modify: `tests/Feature/Api/PaymentTest.php`
- Covers: `app/Services/PaymentService.php` (83%)
- Covers: `app/Services/PaymentConfirmationService.php` (90%)

**Step 1:** Add `covers(PaymentService::class, PaymentConfirmationService::class)`

**Step 2:** Add boundary tests:
- Capture with amount=0 → error
- Capture amount > payment amount → error
- Cancel already cancelled payment → error
- Confirm with connector failure → proper error status stored
- Assert exact amounts after partial capture

**Commit:** `test: strengthen PaymentService mutation coverage`

---

### Task 11: RefundService — boundary conditions

**Files:**
- Modify: `tests/Feature/Api/RefundTest.php`
- Covers: `app/Services/RefundService.php` (80%)

**Step 1:** Add `covers(RefundService::class)`

**Step 2:** Add tests:
- Refund amount > payment amount → error
- Refund on non-succeeded payment → error
- Multiple partial refunds totaling exact payment amount
- Assert exact refund amount and status stored

**Commit:** `test: strengthen RefundService mutation coverage`

---

### Task 12: WebhookReceiverService — concrete assertions

**Files:**
- Modify: `tests/Feature/Api/WebhookReceiverTest.php`
- Covers: `app/Services/WebhookReceiverService.php` (87%)

**Step 1:** Add `covers(WebhookReceiverService::class)`

**Step 2:** Strengthen:
- Assert payment status changed to exact value after webhook
- Assert attempt_count incremented
- Assert connector_transaction_id stored
- Invalid webhook type → no status change

**Commit:** `test: strengthen WebhookReceiverService mutation coverage`

---

## Verification

### Final Check

```bash
# Coverage must be >= 85%
XDEBUG_MODE=coverage ./vendor/bin/pest --coverage --min=85

# Mutation score on business logic must be >= 70%
composer mutate

# All tests pass
./vendor/bin/pest

# PHPStan clean
./vendor/bin/phpstan analyse --no-progress --memory-limit=512M

# Lint clean
composer lint:check
```

### Expected Results

| Metric | Before | Target |
|--------|--------|--------|
| Coverage | 76.7% | >= 85% |
| Mutation Score (Services+Repos+Builders) | 56% | >= 70% |
| Tests passing | 399 | ~450+ |
| PHPStan | 0 errors | 0 errors |

---

## Priority Order

Tasks 1-7 (coverage) are independent — can run in parallel via subagents.
Tasks 8-12 (mutations) depend on Tasks 1-7 being done first.
