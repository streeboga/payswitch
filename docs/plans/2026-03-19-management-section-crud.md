# Management Section — Full CRUD Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Make the entire "Управление" (Management) section fully functional — all tables show data, all forms work (create, edit, delete), all relationships load, detail pages display correctly.

**Architecture:** Follow existing layered pattern: Route → Controller → Service → Repository. Frontend: API endpoint → React Query hook → Page component. All new endpoints follow JSON:API v1.1 format via `JsonApiResource`. Tests: Pest PHP for backend, Vitest for frontend.

**Tech Stack:** Laravel 13 + Pest PHP, React 19 + TypeScript + TanStack Query/Router + Vitest, Spatie Laravel Data DTOs.

---

## Task 1: Backend — Organization Update & Delete

**Files:**
- Create: `app/DataTransferObjects/Admin/UpdateOrganizationData.php`
- Create: `app/Http/Requests/Dashboard/UpdateDashboardOrganizationRequest.php`
- Modify: `app/Repositories/Contracts/MerchantRepositoryInterface.php`
- Modify: `app/Repositories/Eloquent/MerchantRepository.php`
- Modify: `app/Services/MerchantService.php`
- Modify: `app/Http/Controllers/Dashboard/DashboardOrganizationController.php`
- Modify: `app/Http/Resources/OrganizationResource.php` (add merchants_count)
- Modify: `routes/api.php`

**Step 1: Create UpdateOrganizationData DTO**

```php
// app/DataTransferObjects/Admin/UpdateOrganizationData.php
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Admin;

use Spatie\LaravelData\Data;

final class UpdateOrganizationData extends Data
{
    public function __construct(
        public readonly string $name,
    ) {}
}
```

**Step 2: Create UpdateDashboardOrganizationRequest**

```php
// app/Http/Requests/Dashboard/UpdateDashboardOrganizationRequest.php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Dashboard;

use App\DataTransferObjects\Admin\UpdateOrganizationData;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateDashboardOrganizationRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    public function toDto(): UpdateOrganizationData
    {
        return UpdateOrganizationData::from($this->validated());
    }
}
```

**Step 3: Add repository methods**

Add to `MerchantRepositoryInterface`:
```php
public function updateOrganization(Organization $org, array $attributes): Organization;
public function deleteOrganization(Organization $org): void;
```

Add to `MerchantRepository`:
```php
public function updateOrganization(Organization $org, array $attributes): Organization
{
    $org->update($attributes);
    return $org->fresh();
}

public function deleteOrganization(Organization $org): void
{
    $org->delete();
}
```

**Step 4: Add service methods**

Add to `MerchantService`:
```php
public function updateOrganization(string $orgKey, UpdateOrganizationData $dto): Organization
{
    $org = $this->merchantRepository->findOrganizationByKey($orgKey);
    return $this->merchantRepository->updateOrganization($org, $dto->toArray());
}

public function deleteOrganization(string $orgKey): void
{
    $org = $this->merchantRepository->findOrganizationByKey($orgKey);
    $this->merchantRepository->deleteOrganization($org);
}
```

**Step 5: Add controller methods**

Add to `DashboardOrganizationController`:
```php
use App\Http\Requests\Dashboard\UpdateDashboardOrganizationRequest;

public function update(string $orgKey, UpdateDashboardOrganizationRequest $request): JsonResponse
{
    $org = $this->merchantService->updateOrganization($orgKey, $request->toDto());
    return (new OrganizationResource($org))->toResponse($request);
}

public function destroy(string $orgKey): JsonResponse
{
    $this->merchantService->deleteOrganization($orgKey);
    return response()->json(null, 204);
}
```

**Step 6: Update OrganizationResource to include merchants_count**

```php
// In toAttributes():
'merchants_count' => $this->whenCounted('merchantAccounts', $this->merchantAccounts_count ?? 0),
```

Update `listOrganizations()` in repository:
```php
return Organization::withCount('merchantAccounts')->orderByDesc('created_at')->get();
```

And `findOrganizationByKey()`:
```php
return Organization::withCount('merchantAccounts')->where('key', $key)->firstOrFail();
```

**Step 7: Add routes**

In `routes/api.php`, under the no-merchant-context dashboard group, add:
```php
Route::patch('/organizations/{orgKey}', [DashboardOrganizationController::class, 'update']);
Route::delete('/organizations/{orgKey}', [DashboardOrganizationController::class, 'destroy']);
```

**Step 8: Run tests**

```bash
./vendor/bin/pest tests/Feature/Dashboard/OrganizationTest.php
```

---

## Task 2: Backend — Merchant Update & Delete

**Files:**
- Create: `app/DataTransferObjects/Admin/UpdateMerchantAccountData.php`
- Create: `app/Http/Requests/Dashboard/UpdateDashboardMerchantRequest.php`
- Modify: `app/Repositories/Contracts/MerchantRepositoryInterface.php`
- Modify: `app/Repositories/Eloquent/MerchantRepository.php`
- Modify: `app/Services/MerchantService.php`
- Modify: `app/Http/Controllers/Dashboard/DashboardMerchantController.php`
- Modify: `app/Http/Resources/MerchantAccountResource.php` (add counts)
- Modify: `routes/api.php`

**Step 1: Create UpdateMerchantAccountData DTO**

```php
// app/DataTransferObjects/Admin/UpdateMerchantAccountData.php
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Admin;

use Spatie\LaravelData\Data;

final class UpdateMerchantAccountData extends Data
{
    public function __construct(
        public readonly string $name,
    ) {}
}
```

**Step 2: Create UpdateDashboardMerchantRequest**

```php
// app/Http/Requests/Dashboard/UpdateDashboardMerchantRequest.php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Dashboard;

use App\DataTransferObjects\Admin\UpdateMerchantAccountData;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateDashboardMerchantRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    public function toDto(): UpdateMerchantAccountData
    {
        return UpdateMerchantAccountData::from($this->validated());
    }
}
```

**Step 3: Add repository methods**

Add to `MerchantRepositoryInterface`:
```php
public function updateMerchant(MerchantAccount $merchant, array $attributes): MerchantAccount;
public function deleteMerchant(MerchantAccount $merchant): void;
```

Add to `MerchantRepository`:
```php
public function updateMerchant(MerchantAccount $merchant, array $attributes): MerchantAccount
{
    $merchant->update($attributes);
    return $merchant->fresh('organization');
}

public function deleteMerchant(MerchantAccount $merchant): void
{
    $merchant->delete();
}
```

**Step 4: Add service methods**

Add to `MerchantService`:
```php
use App\DataTransferObjects\Admin\UpdateMerchantAccountData;

public function updateMerchant(string $merchantKey, UpdateMerchantAccountData $dto): MerchantAccount
{
    $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);
    return $this->merchantRepository->updateMerchant($merchant, $dto->toArray());
}

public function deleteMerchant(string $merchantKey): void
{
    $merchant = $this->merchantRepository->findMerchantByKey($merchantKey);
    $this->merchantRepository->deleteMerchant($merchant);
}
```

**Step 5: Add controller methods**

Add to `DashboardMerchantController`:
```php
use App\Http\Requests\Dashboard\UpdateDashboardMerchantRequest;

public function update(string $merchantKey, UpdateDashboardMerchantRequest $request): JsonResponse
{
    $merchant = $this->merchantService->updateMerchant($merchantKey, $request->toDto());
    return (new MerchantAccountResource($merchant))->toResponse($request);
}

public function destroy(string $merchantKey): JsonResponse
{
    $this->merchantService->deleteMerchant($merchantKey);
    return response()->json(null, 204);
}
```

**Step 6: Update MerchantAccountResource to include counts**

```php
// In toAttributes():
'profiles_count' => $this->whenCounted('businessProfiles', $this->businessProfiles_count ?? 0),
'connectors_count' => $this->whenCounted('connectorAccounts', $this->connectorAccounts_count ?? 0),
```

Update `listAllMerchants()` in repository:
```php
return MerchantAccount::with('organization')
    ->withCount(['businessProfiles', 'connectorAccounts'])
    ->orderByDesc('created_at')
    ->get();
```

Update `findMerchantByKey()`:
```php
return MerchantAccount::with('organization')
    ->withCount(['businessProfiles', 'connectorAccounts'])
    ->where('key', $key)
    ->firstOrFail();
```

**Step 7: Add routes**

```php
Route::patch('/merchants/{merchantKey}', [DashboardMerchantController::class, 'update']);
Route::delete('/merchants/{merchantKey}', [DashboardMerchantController::class, 'destroy']);
```

---

## Task 3: Backend — Profile Delete

**Files:**
- Modify: `app/Repositories/Contracts/MerchantRepositoryInterface.php`
- Modify: `app/Repositories/Eloquent/MerchantRepository.php`
- Modify: `app/Services/BusinessProfileService.php`
- Modify: `app/Http/Controllers/Dashboard/DashboardBusinessProfileController.php`
- Modify: `app/Http/Resources/BusinessProfileResource.php` (add counts)
- Modify: `routes/api.php`

**Step 1: Add repository method**

Add to interface:
```php
public function deleteProfile(BusinessProfile $profile): void;
```

Add to implementation:
```php
public function deleteProfile(BusinessProfile $profile): void
{
    $profile->delete();
}
```

**Step 2: Add service method**

```php
public function delete(string $profileKey): void
{
    $profile = $this->merchantRepository->findProfileByKey($profileKey);
    $this->merchantRepository->deleteProfile($profile);
}
```

**Step 3: Add controller method**

```php
public function destroy(string $profileKey, Request $request): JsonResponse
{
    $merchantId = $request->attributes->get('merchant_id');
    Gate::authorize('business-profile.delete', [$merchantId]);
    $this->businessProfileService->delete($profileKey);

    return response()->json(null, 204);
}
```

**Step 4: Update BusinessProfileResource to include counts**

```php
'connectors_count' => $this->whenCounted('connectorAccounts', $this->connectorAccounts_count ?? 0),
'routing_rules_count' => $this->whenCounted('routingRules', $this->routingRules_count ?? 0),
```

Note: BusinessProfile model needs `connectorAccounts` and `routingRules` relationships — verify they exist or add `hasMany` if missing.

**Step 5: Add route**

In the merchant-context dashboard group:
```php
Route::delete('/profiles/{profileKey}', [DashboardBusinessProfileController::class, 'destroy']);
```

---

## Task 4: Backend — Database Seeder

**Files:**
- Modify: `database/seeders/DatabaseSeeder.php`

**Step 1: Create comprehensive seeder**

```php
<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Seeder;
use Streeboga\PaymentData\Models\ApiKey;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\RoutingRule;
use Streeboga\PaymentData\Support\IdGenerator;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Admin user
        $admin = User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@payswitch.test',
        ]);

        // Operator user
        $operator = User::factory()->create([
            'name' => 'Operator User',
            'email' => 'operator@payswitch.test',
        ]);

        // Create 3 organizations
        $orgs = collect(['Acme Corp', 'TechStart LLC', 'Global Payments Inc'])->map(
            fn (string $name) => Organization::create(['name' => $name])
        );

        // Assign admin role to all orgs
        foreach ($orgs as $org) {
            UserRole::create([
                'user_id' => $admin->id,
                'organization_id' => $org->id,
                'role' => 'admin',
            ]);
        }

        // Assign operator role to first org
        UserRole::create([
            'user_id' => $operator->id,
            'organization_id' => $orgs[0]->id,
            'role' => 'operator',
        ]);

        // Create merchants per org
        $merchantNames = [
            ['Web Store', 'Mobile App'],
            ['SaaS Platform'],
            ['EU Store', 'US Store', 'Asia Store'],
        ];

        foreach ($orgs as $i => $org) {
            foreach ($merchantNames[$i] as $merchantName) {
                $merchant = MerchantAccount::create([
                    'org_id' => $org->id,
                    'name' => $merchantName,
                ]);

                // Create a business profile per merchant
                $profile = BusinessProfile::create([
                    'merchant_account_id' => $merchant->id,
                    'webhook_url' => "https://example.com/webhooks/{$merchant->key}",
                ]);

                // Create API key
                $rawKey = IdGenerator::apiKey(config('payswitch.environment', 'sandbox'));
                ApiKey::create([
                    'merchant_account_id' => $merchant->id,
                    'key_hash' => hash('sha256', $rawKey),
                    'key_prefix' => substr($rawKey, 0, 20),
                    'name' => "Default key for {$merchantName}",
                ]);

                // Create a test connector
                MerchantConnectorAccount::create([
                    'merchant_account_id' => $merchant->id,
                    'business_profile_id' => $profile->id,
                    'connector_name' => 'dummy_connector',
                    'connector_type' => 'payment_processor',
                    'connector_account_details' => ['api_key' => 'test_key_' . $merchant->key],
                    'test_mode' => true,
                ]);

                // Create a routing rule
                RoutingRule::create([
                    'merchant_account_id' => $merchant->id,
                    'business_profile_id' => $profile->id,
                    'type' => 'volume_split',
                    'name' => "Default routing for {$merchantName}",
                    'rules' => [['connector' => 'dummy_connector', 'weight' => 100]],
                    'active' => true,
                    'priority' => 0,
                ]);
            }
        }
    }
}
```

**Step 2: Run seeder**

```bash
php artisan migrate:fresh --seed
```

---

## Task 5: Backend — Tests for New Endpoints

**Files:**
- Modify: `tests/Feature/Dashboard/OrganizationTest.php`
- Create: `tests/Feature/Dashboard/MerchantTest.php`
- Create: `tests/Feature/Dashboard/ProfileTest.php`

**Step 1: Add organization update/delete tests**

Append to `tests/Feature/Dashboard/OrganizationTest.php`:
```php
test('organization can be created', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/dashboard/organizations', ['name' => 'New Org'], $this->headers);

    $response->assertStatus(201)
        ->assertJsonPath('data.type', 'organizations')
        ->assertJsonPath('data.attributes.name', 'New Org');
});

test('organization can be updated', function () {
    $response = $this->actingAs($this->user)
        ->patchJson("/api/v1/dashboard/organizations/{$this->org->key}", [
            'name' => 'Updated Org',
        ], $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.attributes.name', 'Updated Org');
});

test('organization update requires name', function () {
    $response = $this->actingAs($this->user)
        ->patchJson("/api/v1/dashboard/organizations/{$this->org->key}", [
            'name' => '',
        ], $this->headers);

    $response->assertUnprocessable();
});

test('organization can be deleted', function () {
    $response = $this->actingAs($this->user)
        ->deleteJson("/api/v1/dashboard/organizations/{$this->org->key}", [], $this->headers);

    $response->assertNoContent();
    $this->assertDatabaseMissing('organizations', ['id' => $this->org->id]);
});

test('organization deletion cascades to merchants', function () {
    $this->actingAs($this->user)
        ->deleteJson("/api/v1/dashboard/organizations/{$this->org->key}", [], $this->headers);

    $this->assertDatabaseMissing('merchant_accounts', ['id' => $this->merchant->id]);
});

test('organization includes merchants_count', function () {
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/organizations', $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.0.attributes.merchants_count', 1);
});
```

**Step 2: Create merchant tests**

```php
// tests/Feature/Dashboard/MerchantTest.php
<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $this->org->id, 'name' => 'Merchant']);
    $this->headers = ['X-Merchant-Key' => $this->merchant->key];
});

test('merchants list returns json:api response', function () {
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/merchants', $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.0.type', 'merchants');
});

test('merchant detail returns json:api resource', function () {
    $response = $this->actingAs($this->user)
        ->getJson("/api/v1/dashboard/merchants/{$this->merchant->key}", $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.type', 'merchants')
        ->assertJsonPath('data.id', $this->merchant->key);
});

test('merchant can be created', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/dashboard/merchants', [
            'name' => 'New Merchant',
            'organization_id' => $this->org->key,
        ], $this->headers);

    $response->assertStatus(201)
        ->assertJsonPath('data.attributes.name', 'New Merchant');
});

test('merchant can be updated', function () {
    $response = $this->actingAs($this->user)
        ->patchJson("/api/v1/dashboard/merchants/{$this->merchant->key}", [
            'name' => 'Updated Merchant',
        ], $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.attributes.name', 'Updated Merchant');
});

test('merchant can be deleted', function () {
    $response = $this->actingAs($this->user)
        ->deleteJson("/api/v1/dashboard/merchants/{$this->merchant->key}", [], $this->headers);

    $response->assertNoContent();
    $this->assertDatabaseMissing('merchant_accounts', ['id' => $this->merchant->id]);
});

test('merchants require authentication', function () {
    $response = $this->getJson('/api/v1/dashboard/merchants', $this->headers);
    $response->assertUnauthorized();
});
```

---

## Task 6: Frontend — API Endpoints for Update/Delete

**Files:**
- Modify: `dashboard/src/api/endpoints/dashboard-orgs.ts`

**Step 1: Add update/delete methods**

```typescript
// Add to dashboardOrgs object:

async updateOrg(orgKey: string, data: { name: string }) {
  const doc = await updateResource<OrganizationAttributes>(`dashboard/organizations/${orgKey}`, data)
  return extractAttributes(doc.data)
},

async deleteOrg(orgKey: string) {
  await deleteResource(`dashboard/organizations/${orgKey}`)
},

async updateMerchant(merchantKey: string, data: { name: string }) {
  const doc = await updateResource<MerchantAccountAttributes>(`dashboard/merchants/${merchantKey}`, data)
  return extractAttributes(doc.data)
},

async deleteMerchant(merchantKey: string) {
  await deleteResource(`dashboard/merchants/${merchantKey}`)
},
```

Add imports: `updateResource, deleteResource` from `'../client'`

**Step 2: Add profile delete to dashboard-profiles.ts**

```typescript
async delete(profileKey: string) {
  await deleteResource(`dashboard/profiles/${profileKey}`)
},
```

---

## Task 7: Frontend — React Query Hooks for Update/Delete

**Files:**
- Modify: `dashboard/src/hooks/use-organizations.ts`
- Modify: `dashboard/src/hooks/use-merchants.ts`
- Modify: `dashboard/src/hooks/use-profiles.ts`

**Step 1: Add org hooks**

```typescript
export function useUpdateOrganization() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ orgKey, data }: { orgKey: string; data: { name: string } }) =>
      dashboardOrgs.updateOrg(orgKey, data),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['organizations'] })
    },
  })
}

export function useDeleteOrganization() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (orgKey: string) => dashboardOrgs.deleteOrg(orgKey),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['organizations'] })
    },
  })
}
```

**Step 2: Add merchant hooks**

```typescript
export function useUpdateMerchant() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ merchantKey, data }: { merchantKey: string; data: { name: string } }) =>
      dashboardOrgs.updateMerchant(merchantKey, data),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['merchants'] })
      void queryClient.invalidateQueries({ queryKey: ['organizations'] })
    },
  })
}

export function useDeleteMerchant() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (merchantKey: string) => dashboardOrgs.deleteMerchant(merchantKey),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['merchants'] })
      void queryClient.invalidateQueries({ queryKey: ['organizations'] })
    },
  })
}
```

**Step 3: Add profile delete hook**

```typescript
export function useDeleteProfile() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (profileKey: string) => dashboardProfiles.delete(profileKey),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['profiles'] })
    },
  })
}
```

---

## Task 8: Frontend — Organizations Page (Edit/Delete)

**Files:**
- Modify: `dashboard/src/pages/organizations.tsx`

**Step 1: Add edit and delete dialogs, row action menu**

Add an actions column with DropdownMenu (edit, delete). Add EditOrgDialog and DeleteOrgDialog components following the pattern from CreateOrgDialog.

Edit dialog: form with name field, pre-filled from row data. Uses `useUpdateOrganization()`.

Delete dialog: AlertDialog with confirmation. Uses `useDeleteOrganization()`.

Actions column:
```typescript
{
  id: 'actions',
  size: 50,
  cell: ({ row }) => (
    <RowActions
      onEdit={() => { setEditOrg(row.original); setEditOpen(true) }}
      onDelete={() => { setDeleteOrg(row.original); setDeleteOpen(true) }}
    />
  ),
},
```

---

## Task 9: Frontend — Organization Detail Page (Edit/Delete)

**Files:**
- Modify: `dashboard/src/pages/organization-detail.tsx`

Add edit button in header (pencil icon), delete button (trash icon). Inline edit for name. Delete navigates back to /organizations after success using `useNavigate()`.

---

## Task 10: Frontend — Merchants Page (Edit/Delete)

**Files:**
- Modify: `dashboard/src/pages/merchants.tsx`

Same pattern as organizations — add actions column, edit dialog, delete dialog. Edit dialog includes name field only (org cannot be changed). Uses `useUpdateMerchant()` and `useDeleteMerchant()`.

---

## Task 11: Frontend — Merchant Detail Page (Edit/Delete + Real Tab Data)

**Files:**
- Modify: `dashboard/src/pages/merchant-detail.tsx`

**Step 1: Add edit/delete in header**

Edit button → inline edit for name. Delete button → confirmation + navigate to /merchants.

**Step 2: Replace tab stubs with real data**

- **Profiles tab**: Use `useProfilesList()` (from existing hooks) to fetch profiles for this merchant. Show a DataTable with columns: ID, Webhook URL, Created. Add create profile button.
- **API Keys tab**: Use `useApiKeysList()` to fetch keys. Show DataTable with columns: Name, Prefix, Type, Status, Created. Add create/revoke buttons.
- **Connectors tab**: Render DataTable with connector data. (Note: connectors are fetched via merchant context — existing hooks should work.)
- **Routing tab**: Same approach — DataTable with routing rules.

For the profiles and API keys tabs, the merchant detail page should pass the merchant key to the hooks so they fetch data scoped to this merchant.

---

## Task 12: Frontend — Profiles Page (Delete)

**Files:**
- Modify: `dashboard/src/pages/profiles.tsx`

Add actions column with delete button. Add DeleteProfileDialog with confirmation.

---

## Task 13: Frontend — Translations (en + ru)

**Files:**
- Modify: `dashboard/src/locales/en.json`
- Modify: `dashboard/src/locales/ru.json`

Add keys for:
```json
{
  "organizations.editTitle": "Edit organization",
  "organizations.editDesc": "Change the organization name.",
  "organizations.deleteTitle": "Delete organization",
  "organizations.deleteDesc": "This action cannot be undone. All merchants and their data will be permanently deleted.",
  "organizations.deleteConfirm": "Delete",
  "merchants.editTitle": "Edit merchant",
  "merchants.editDesc": "Change the merchant name.",
  "merchants.deleteTitle": "Delete merchant",
  "merchants.deleteDesc": "This action cannot be undone. All profiles, API keys, and connectors will be permanently deleted.",
  "merchants.deleteConfirm": "Delete",
  "profiles.deleteTitle": "Delete profile",
  "profiles.deleteDesc": "This action cannot be undone. All connectors and routing rules linked to this profile will be affected.",
  "profiles.deleteConfirm": "Delete",
  "common.edit": "Edit",
  "common.delete": "Delete",
  "common.save": "Save",
  "common.saving": "Saving...",
  "common.deleting": "Deleting...",
  "common.actions": "Actions"
}
```

Russian equivalents for all keys.

---

## Task 14: Frontend — Tests

**Files:**
- Modify: `dashboard/src/pages/__tests__/organizations.test.tsx`
- Modify: `dashboard/src/pages/__tests__/merchants.test.tsx`

Add tests for:
- Edit dialog renders and submits
- Delete dialog renders and confirms
- Mock API methods include `updateOrg`, `deleteOrg`, `updateMerchant`, `deleteMerchant`

---

## Task 15: Final Verification

**Step 1: Run backend tests**
```bash
./vendor/bin/pest
```

**Step 2: Run frontend checks**
```bash
cd dashboard && npm run lint:check && npm run format:check && npm run types:check && npm run test
```

**Step 3: Run seeder and verify manually**
```bash
php artisan migrate:fresh --seed
composer dev
# Open http://localhost:3000/organizations and verify all CRUD operations
```

---

## Execution Notes

- Tasks 1-5 are backend, can be done first as a batch
- Tasks 6-7 are frontend infrastructure (endpoints + hooks)
- Tasks 8-12 are frontend pages, each independent
- Task 13 (translations) should be done alongside page changes
- Task 14 (tests) can be done per-page or as a batch
- Task 15 is final verification
