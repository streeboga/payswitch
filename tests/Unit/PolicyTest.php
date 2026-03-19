<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use App\Policies\AnalyticsPolicy;
use App\Policies\ApiKeyPolicy;
use App\Policies\AuditLogPolicy;
use App\Policies\BusinessProfilePolicy;
use App\Policies\ConnectorHealthPolicy;
use App\Policies\ConnectorPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\DisputePolicy;
use App\Policies\EventLogPolicy;
use App\Policies\MerchantAccountPolicy;
use App\Policies\NotificationPolicy;
use App\Policies\OrganizationPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\RoutingRulePolicy;
use App\Policies\SavedFilterPolicy;
use App\Policies\TestPaymentPolicy;
use App\Policies\UserRolePolicy;
use App\Policies\UserSettingsPolicy;
use App\Policies\WebhookEventPolicy;
use Illuminate\Database\Eloquent\Relations\HasMany;

uses()->group('unit');

// ---- Helpers ----

function mockUserWithMerchantRole(?UserRole $role): User
{
    $user = Mockery::mock(User::class);
    $user->shouldReceive('roleForMerchant')->andReturn($role);

    return $user;
}

function mockUserWithOrganizationRole(?UserRole $role, bool $hasRoles = true): User
{
    $user = Mockery::mock(User::class);
    $user->shouldReceive('roleForOrganization')->andReturn($role);

    $rolesRelation = Mockery::mock(HasMany::class);
    $rolesRelation->shouldReceive('exists')->andReturn($hasRoles);
    $rolesRelation->shouldReceive('where->exists')->andReturn($role === UserRole::Admin);
    $user->shouldReceive('roles')->andReturn($rolesRelation);

    return $user;
}

// ---- MerchantPolicy-based policies: Admin can do everything ----

// ApiKeyPolicy

test('ApiKeyPolicy: admin can viewAny, create, delete', function () {
    $user = mockUserWithMerchantRole(UserRole::Admin);
    $policy = new ApiKeyPolicy;

    expect($policy->viewAny($user, 1))->toBeTrue();
    expect($policy->create($user, 1))->toBeTrue();
    expect($policy->delete($user, 1))->toBeTrue();
});

test('ApiKeyPolicy: operator can viewAny but not create or delete', function () {
    $user = mockUserWithMerchantRole(UserRole::Operator);
    $policy = new ApiKeyPolicy;

    expect($policy->viewAny($user, 1))->toBeTrue();
    expect($policy->create($user, 1))->toBeFalse();
    expect($policy->delete($user, 1))->toBeFalse();
});

test('ApiKeyPolicy: viewer can viewAny but not create or delete', function () {
    $user = mockUserWithMerchantRole(UserRole::Viewer);
    $policy = new ApiKeyPolicy;

    expect($policy->viewAny($user, 1))->toBeTrue();
    expect($policy->create($user, 1))->toBeFalse();
    expect($policy->delete($user, 1))->toBeFalse();
});

test('ApiKeyPolicy: no-role user is denied everything', function () {
    $user = mockUserWithMerchantRole(null);
    $policy = new ApiKeyPolicy;

    expect($policy->viewAny($user, 1))->toBeFalse();
    expect($policy->create($user, 1))->toBeFalse();
    expect($policy->delete($user, 1))->toBeFalse();
});

// AnalyticsPolicy

test('AnalyticsPolicy: admin can viewAny', function () {
    $user = mockUserWithMerchantRole(UserRole::Admin);
    expect((new AnalyticsPolicy)->viewAny($user, 1))->toBeTrue();
});

test('AnalyticsPolicy: viewer can viewAny', function () {
    $user = mockUserWithMerchantRole(UserRole::Viewer);
    expect((new AnalyticsPolicy)->viewAny($user, 1))->toBeTrue();
});

test('AnalyticsPolicy: no-role user is denied', function () {
    $user = mockUserWithMerchantRole(null);
    expect((new AnalyticsPolicy)->viewAny($user, 1))->toBeFalse();
});

// AuditLogPolicy

test('AuditLogPolicy: any role can viewAny and export', function () {
    foreach (UserRole::cases() as $role) {
        $user = mockUserWithMerchantRole($role);
        $policy = new AuditLogPolicy;
        expect($policy->viewAny($user, 1))->toBeTrue();
        expect($policy->export($user, 1))->toBeTrue();
    }
});

test('AuditLogPolicy: no-role user is denied', function () {
    $user = mockUserWithMerchantRole(null);
    $policy = new AuditLogPolicy;
    expect($policy->viewAny($user, 1))->toBeFalse();
    expect($policy->export($user, 1))->toBeFalse();
});

// BusinessProfilePolicy

test('BusinessProfilePolicy: admin can viewAny, view, create, update', function () {
    $user = mockUserWithMerchantRole(UserRole::Admin);
    $policy = new BusinessProfilePolicy;
    expect($policy->viewAny($user, 1))->toBeTrue();
    expect($policy->view($user, 1))->toBeTrue();
    expect($policy->create($user, 1))->toBeTrue();
    expect($policy->update($user, 1))->toBeTrue();
});

test('BusinessProfilePolicy: operator can view but not create/update', function () {
    $user = mockUserWithMerchantRole(UserRole::Operator);
    $policy = new BusinessProfilePolicy;
    expect($policy->viewAny($user, 1))->toBeTrue();
    expect($policy->view($user, 1))->toBeTrue();
    expect($policy->create($user, 1))->toBeFalse();
    expect($policy->update($user, 1))->toBeFalse();
});

test('BusinessProfilePolicy: no-role user is denied', function () {
    $user = mockUserWithMerchantRole(null);
    $policy = new BusinessProfilePolicy;
    expect($policy->viewAny($user, 1))->toBeFalse();
    expect($policy->view($user, 1))->toBeFalse();
    expect($policy->create($user, 1))->toBeFalse();
    expect($policy->update($user, 1))->toBeFalse();
});

// ConnectorHealthPolicy

test('ConnectorHealthPolicy: any role can view', function () {
    foreach (UserRole::cases() as $role) {
        $user = mockUserWithMerchantRole($role);
        expect((new ConnectorHealthPolicy)->view($user, 1))->toBeTrue();
    }
});

test('ConnectorHealthPolicy: no-role user is denied', function () {
    $user = mockUserWithMerchantRole(null);
    expect((new ConnectorHealthPolicy)->view($user, 1))->toBeFalse();
});

// ConnectorPolicy

test('ConnectorPolicy: admin can viewAny, view, create, update, delete', function () {
    $user = mockUserWithMerchantRole(UserRole::Admin);
    $policy = new ConnectorPolicy;
    expect($policy->viewAny($user, 1))->toBeTrue();
    expect($policy->view($user, 1))->toBeTrue();
    expect($policy->create($user, 1))->toBeTrue();
    expect($policy->update($user, 1))->toBeTrue();
    expect($policy->delete($user, 1))->toBeTrue();
});

test('ConnectorPolicy: operator can view but not create/update/delete', function () {
    $user = mockUserWithMerchantRole(UserRole::Operator);
    $policy = new ConnectorPolicy;
    expect($policy->viewAny($user, 1))->toBeTrue();
    expect($policy->view($user, 1))->toBeTrue();
    expect($policy->create($user, 1))->toBeFalse();
    expect($policy->update($user, 1))->toBeFalse();
    expect($policy->delete($user, 1))->toBeFalse();
});

test('ConnectorPolicy: no-role user is denied', function () {
    $user = mockUserWithMerchantRole(null);
    $policy = new ConnectorPolicy;
    expect($policy->viewAny($user, 1))->toBeFalse();
    expect($policy->view($user, 1))->toBeFalse();
    expect($policy->create($user, 1))->toBeFalse();
    expect($policy->update($user, 1))->toBeFalse();
    expect($policy->delete($user, 1))->toBeFalse();
});

// CustomerPolicy

test('CustomerPolicy: admin can viewAny, view, create, update, delete', function () {
    $user = mockUserWithMerchantRole(UserRole::Admin);
    $policy = new CustomerPolicy;
    expect($policy->viewAny($user, 1))->toBeTrue();
    expect($policy->view($user, 1))->toBeTrue();
    expect($policy->create($user, 1))->toBeTrue();
    expect($policy->update($user, 1))->toBeTrue();
    expect($policy->delete($user, 1))->toBeTrue();
});

test('CustomerPolicy: operator can view, create, update but not delete', function () {
    $user = mockUserWithMerchantRole(UserRole::Operator);
    $policy = new CustomerPolicy;
    expect($policy->viewAny($user, 1))->toBeTrue();
    expect($policy->view($user, 1))->toBeTrue();
    expect($policy->create($user, 1))->toBeTrue();
    expect($policy->update($user, 1))->toBeTrue();
    expect($policy->delete($user, 1))->toBeFalse();
});

test('CustomerPolicy: viewer can only view', function () {
    $user = mockUserWithMerchantRole(UserRole::Viewer);
    $policy = new CustomerPolicy;
    expect($policy->viewAny($user, 1))->toBeTrue();
    expect($policy->view($user, 1))->toBeTrue();
    expect($policy->create($user, 1))->toBeFalse();
    expect($policy->update($user, 1))->toBeFalse();
    expect($policy->delete($user, 1))->toBeFalse();
});

test('CustomerPolicy: no-role user is denied', function () {
    $user = mockUserWithMerchantRole(null);
    $policy = new CustomerPolicy;
    expect($policy->viewAny($user, 1))->toBeFalse();
    expect($policy->create($user, 1))->toBeFalse();
    expect($policy->delete($user, 1))->toBeFalse();
});

// DisputePolicy

test('DisputePolicy: admin can viewAny, view, submitEvidence', function () {
    $user = mockUserWithMerchantRole(UserRole::Admin);
    $policy = new DisputePolicy;
    expect($policy->viewAny($user, 1))->toBeTrue();
    expect($policy->view($user, 1))->toBeTrue();
    expect($policy->submitEvidence($user, 1))->toBeTrue();
});

test('DisputePolicy: operator can viewAny, view, submitEvidence', function () {
    $user = mockUserWithMerchantRole(UserRole::Operator);
    $policy = new DisputePolicy;
    expect($policy->viewAny($user, 1))->toBeTrue();
    expect($policy->view($user, 1))->toBeTrue();
    expect($policy->submitEvidence($user, 1))->toBeTrue();
});

test('DisputePolicy: viewer can view but not submitEvidence', function () {
    $user = mockUserWithMerchantRole(UserRole::Viewer);
    $policy = new DisputePolicy;
    expect($policy->viewAny($user, 1))->toBeTrue();
    expect($policy->view($user, 1))->toBeTrue();
    expect($policy->submitEvidence($user, 1))->toBeFalse();
});

test('DisputePolicy: no-role user is denied', function () {
    $user = mockUserWithMerchantRole(null);
    $policy = new DisputePolicy;
    expect($policy->viewAny($user, 1))->toBeFalse();
    expect($policy->view($user, 1))->toBeFalse();
    expect($policy->submitEvidence($user, 1))->toBeFalse();
});

// EventLogPolicy

test('EventLogPolicy: any role can viewAny', function () {
    foreach (UserRole::cases() as $role) {
        $user = mockUserWithMerchantRole($role);
        expect((new EventLogPolicy)->viewAny($user, 1))->toBeTrue();
    }
});

test('EventLogPolicy: no-role user is denied', function () {
    $user = mockUserWithMerchantRole(null);
    expect((new EventLogPolicy)->viewAny($user, 1))->toBeFalse();
});

// PaymentPolicy

test('PaymentPolicy: any role can viewAny, view, export', function () {
    foreach (UserRole::cases() as $role) {
        $user = mockUserWithMerchantRole($role);
        $policy = new PaymentPolicy;
        expect($policy->viewAny($user, 1))->toBeTrue();
        expect($policy->view($user, 1))->toBeTrue();
        expect($policy->export($user, 1))->toBeTrue();
    }
});

test('PaymentPolicy: no-role user is denied', function () {
    $user = mockUserWithMerchantRole(null);
    $policy = new PaymentPolicy;
    expect($policy->viewAny($user, 1))->toBeFalse();
    expect($policy->view($user, 1))->toBeFalse();
    expect($policy->export($user, 1))->toBeFalse();
});

// RoutingRulePolicy

test('RoutingRulePolicy: admin can viewAny, view, create, update, delete', function () {
    $user = mockUserWithMerchantRole(UserRole::Admin);
    $policy = new RoutingRulePolicy;
    expect($policy->viewAny($user, 1))->toBeTrue();
    expect($policy->view($user, 1))->toBeTrue();
    expect($policy->create($user, 1))->toBeTrue();
    expect($policy->update($user, 1))->toBeTrue();
    expect($policy->delete($user, 1))->toBeTrue();
});

test('RoutingRulePolicy: operator can view, create, update but not delete', function () {
    $user = mockUserWithMerchantRole(UserRole::Operator);
    $policy = new RoutingRulePolicy;
    expect($policy->viewAny($user, 1))->toBeTrue();
    expect($policy->view($user, 1))->toBeTrue();
    expect($policy->create($user, 1))->toBeTrue();
    expect($policy->update($user, 1))->toBeTrue();
    expect($policy->delete($user, 1))->toBeFalse();
});

test('RoutingRulePolicy: viewer can only view', function () {
    $user = mockUserWithMerchantRole(UserRole::Viewer);
    $policy = new RoutingRulePolicy;
    expect($policy->viewAny($user, 1))->toBeTrue();
    expect($policy->view($user, 1))->toBeTrue();
    expect($policy->create($user, 1))->toBeFalse();
    expect($policy->update($user, 1))->toBeFalse();
    expect($policy->delete($user, 1))->toBeFalse();
});

test('RoutingRulePolicy: no-role user is denied', function () {
    $user = mockUserWithMerchantRole(null);
    $policy = new RoutingRulePolicy;
    expect($policy->viewAny($user, 1))->toBeFalse();
    expect($policy->create($user, 1))->toBeFalse();
    expect($policy->delete($user, 1))->toBeFalse();
});

// TestPaymentPolicy

test('TestPaymentPolicy: admin and operator can create', function () {
    $admin = mockUserWithMerchantRole(UserRole::Admin);
    $operator = mockUserWithMerchantRole(UserRole::Operator);
    $policy = new TestPaymentPolicy;
    expect($policy->create($admin, 1))->toBeTrue();
    expect($policy->create($operator, 1))->toBeTrue();
});

test('TestPaymentPolicy: viewer cannot create', function () {
    $user = mockUserWithMerchantRole(UserRole::Viewer);
    expect((new TestPaymentPolicy)->create($user, 1))->toBeFalse();
});

test('TestPaymentPolicy: no-role user is denied', function () {
    $user = mockUserWithMerchantRole(null);
    expect((new TestPaymentPolicy)->create($user, 1))->toBeFalse();
});

// UserRolePolicy

test('UserRolePolicy: admin can viewAny, assign, update, delete', function () {
    $user = mockUserWithMerchantRole(UserRole::Admin);
    $policy = new UserRolePolicy;
    expect($policy->viewAny($user, 1))->toBeTrue();
    expect($policy->assign($user, 1))->toBeTrue();
    expect($policy->update($user, 1))->toBeTrue();
    expect($policy->delete($user, 1))->toBeTrue();
});

test('UserRolePolicy: operator can viewAny but not assign/update/delete', function () {
    $user = mockUserWithMerchantRole(UserRole::Operator);
    $policy = new UserRolePolicy;
    expect($policy->viewAny($user, 1))->toBeTrue();
    expect($policy->assign($user, 1))->toBeFalse();
    expect($policy->update($user, 1))->toBeFalse();
    expect($policy->delete($user, 1))->toBeFalse();
});

test('UserRolePolicy: no-role user is denied', function () {
    $user = mockUserWithMerchantRole(null);
    $policy = new UserRolePolicy;
    expect($policy->viewAny($user, 1))->toBeFalse();
    expect($policy->assign($user, 1))->toBeFalse();
    expect($policy->delete($user, 1))->toBeFalse();
});

// WebhookEventPolicy

test('WebhookEventPolicy: admin can viewAny and retry', function () {
    $user = mockUserWithMerchantRole(UserRole::Admin);
    $policy = new WebhookEventPolicy;
    expect($policy->viewAny($user, 1))->toBeTrue();
    expect($policy->retry($user, 1))->toBeTrue();
});

test('WebhookEventPolicy: operator can viewAny and retry', function () {
    $user = mockUserWithMerchantRole(UserRole::Operator);
    $policy = new WebhookEventPolicy;
    expect($policy->viewAny($user, 1))->toBeTrue();
    expect($policy->retry($user, 1))->toBeTrue();
});

test('WebhookEventPolicy: viewer can viewAny but not retry', function () {
    $user = mockUserWithMerchantRole(UserRole::Viewer);
    $policy = new WebhookEventPolicy;
    expect($policy->viewAny($user, 1))->toBeTrue();
    expect($policy->retry($user, 1))->toBeFalse();
});

test('WebhookEventPolicy: no-role user is denied', function () {
    $user = mockUserWithMerchantRole(null);
    $policy = new WebhookEventPolicy;
    expect($policy->viewAny($user, 1))->toBeFalse();
    expect($policy->retry($user, 1))->toBeFalse();
});

// ---- Non-MerchantPolicy policies ----

// NotificationPolicy

test('NotificationPolicy: any user can viewAny, markRead, delete', function () {
    $user = Mockery::mock(User::class);
    $policy = new NotificationPolicy;
    expect($policy->viewAny($user))->toBeTrue();
    expect($policy->markRead($user))->toBeTrue();
    expect($policy->delete($user))->toBeTrue();
});

// SavedFilterPolicy

test('SavedFilterPolicy: any user can viewAny, create, delete', function () {
    $user = Mockery::mock(User::class);
    $policy = new SavedFilterPolicy;
    expect($policy->viewAny($user))->toBeTrue();
    expect($policy->create($user))->toBeTrue();
    expect($policy->delete($user))->toBeTrue();
});

// UserSettingsPolicy

test('UserSettingsPolicy: any user can view and update', function () {
    $user = Mockery::mock(User::class);
    $policy = new UserSettingsPolicy;
    expect($policy->view($user))->toBeTrue();
    expect($policy->update($user))->toBeTrue();
});

// OrganizationPolicy

test('OrganizationPolicy: user with roles can viewAny', function () {
    $user = mockUserWithOrganizationRole(UserRole::Admin);
    expect((new OrganizationPolicy)->viewAny($user))->toBeTrue();
});

test('OrganizationPolicy: user without roles cannot viewAny', function () {
    $user = mockUserWithOrganizationRole(null, false);
    expect((new OrganizationPolicy)->viewAny($user))->toBeFalse();
});

test('OrganizationPolicy: admin can view, create, update, delete', function () {
    $user = mockUserWithOrganizationRole(UserRole::Admin);
    $policy = new OrganizationPolicy;
    expect($policy->view($user, 1))->toBeTrue();
    expect($policy->create($user))->toBeTrue();
    expect($policy->update($user, 1))->toBeTrue();
    expect($policy->delete($user, 1))->toBeTrue();
});

test('OrganizationPolicy: operator can view but not create, update, delete', function () {
    $user = mockUserWithOrganizationRole(UserRole::Operator);
    $policy = new OrganizationPolicy;
    expect($policy->view($user, 1))->toBeTrue();
    expect($policy->create($user))->toBeFalse();
    expect($policy->update($user, 1))->toBeFalse();
    expect($policy->delete($user, 1))->toBeFalse();
});

test('OrganizationPolicy: no-role user is denied view', function () {
    $user = mockUserWithOrganizationRole(null, false);
    $policy = new OrganizationPolicy;
    expect($policy->view($user, 1))->toBeFalse();
    expect($policy->create($user))->toBeFalse();
    expect($policy->update($user, 1))->toBeFalse();
    expect($policy->delete($user, 1))->toBeFalse();
});

// MerchantAccountPolicy

test('MerchantAccountPolicy: user with roles can viewAny', function () {
    $user = mockUserWithOrganizationRole(UserRole::Admin);
    $user->shouldReceive('roleForMerchant')->andReturn(UserRole::Admin);
    expect((new MerchantAccountPolicy)->viewAny($user))->toBeTrue();
});

test('MerchantAccountPolicy: admin can view, create, update, delete', function () {
    $user = mockUserWithOrganizationRole(UserRole::Admin);
    $user->shouldReceive('roleForMerchant')->andReturn(UserRole::Admin);
    $policy = new MerchantAccountPolicy;
    expect($policy->view($user, 1))->toBeTrue();
    expect($policy->create($user, 1))->toBeTrue();
    expect($policy->update($user, 1))->toBeTrue();
    expect($policy->delete($user, 1))->toBeTrue();
});

test('MerchantAccountPolicy: operator can view, update but not create or delete', function () {
    $user = mockUserWithOrganizationRole(UserRole::Operator);
    $user->shouldReceive('roleForMerchant')->andReturn(UserRole::Operator);
    $policy = new MerchantAccountPolicy;
    expect($policy->view($user, 1))->toBeTrue();
    expect($policy->create($user, 1))->toBeFalse();
    expect($policy->update($user, 1))->toBeTrue();
    expect($policy->delete($user, 1))->toBeFalse();
});

test('MerchantAccountPolicy: viewer can view but not update, create, delete', function () {
    $user = mockUserWithOrganizationRole(UserRole::Viewer);
    $user->shouldReceive('roleForMerchant')->andReturn(UserRole::Viewer);
    $policy = new MerchantAccountPolicy;
    expect($policy->view($user, 1))->toBeTrue();
    expect($policy->create($user, 1))->toBeFalse();
    expect($policy->update($user, 1))->toBeFalse();
    expect($policy->delete($user, 1))->toBeFalse();
});

test('MerchantAccountPolicy: no-role user is denied', function () {
    $user = mockUserWithOrganizationRole(null, false);
    $user->shouldReceive('roleForMerchant')->andReturn(null);
    $policy = new MerchantAccountPolicy;
    expect($policy->viewAny($user))->toBeFalse();
    expect($policy->view($user, 1))->toBeFalse();
    expect($policy->create($user, 1))->toBeFalse();
    expect($policy->update($user, 1))->toBeFalse();
    expect($policy->delete($user, 1))->toBeFalse();
});

// ---- All policy methods return bool ----

test('all merchant-based policy methods return bool', function () {
    $policies = [
        ApiKeyPolicy::class => ['viewAny', 'create', 'delete'],
        AnalyticsPolicy::class => ['viewAny'],
        AuditLogPolicy::class => ['viewAny', 'export'],
        BusinessProfilePolicy::class => ['viewAny', 'view', 'create', 'update'],
        ConnectorHealthPolicy::class => ['view'],
        ConnectorPolicy::class => ['viewAny', 'view', 'create', 'update', 'delete'],
        CustomerPolicy::class => ['viewAny', 'view', 'create', 'update', 'delete'],
        DisputePolicy::class => ['viewAny', 'view', 'submitEvidence'],
        EventLogPolicy::class => ['viewAny'],
        PaymentPolicy::class => ['viewAny', 'view', 'export'],
        RoutingRulePolicy::class => ['viewAny', 'view', 'create', 'update', 'delete'],
        TestPaymentPolicy::class => ['create'],
        UserRolePolicy::class => ['viewAny', 'assign', 'update', 'delete'],
        WebhookEventPolicy::class => ['viewAny', 'retry'],
    ];

    $user = mockUserWithMerchantRole(UserRole::Admin);

    foreach ($policies as $policyClass => $methods) {
        $policy = new $policyClass;
        foreach ($methods as $method) {
            expect($policy->$method($user, 1))->toBeBool();
        }
    }
});
