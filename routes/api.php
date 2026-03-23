<?php

use App\Http\Controllers\Api\V1\Admin;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PaymentMethodController;
use App\Http\Controllers\Api\V1\PublicPaymentController;
use App\Http\Controllers\Api\V1\PublicPaymentStatusController;
use App\Http\Controllers\Api\V1\RefundController;
use App\Http\Controllers\Api\V1\WebhookReceiverController;
use App\Http\Controllers\Auth\UserController;
use App\Http\Controllers\Dashboard\AnalyticsController;
use App\Http\Controllers\Dashboard\AuditLogController;
use App\Http\Controllers\Dashboard\ConnectorHealthController;
use App\Http\Controllers\Dashboard\DashboardApiKeyController;
use App\Http\Controllers\Dashboard\DashboardBusinessProfileController;
use App\Http\Controllers\Dashboard\DashboardConnectorController;
use App\Http\Controllers\Dashboard\DashboardCustomerController;
use App\Http\Controllers\Dashboard\DashboardMerchantController;
use App\Http\Controllers\Dashboard\DashboardOrganizationController;
use App\Http\Controllers\Dashboard\DashboardPaymentController;
use App\Http\Controllers\Dashboard\DashboardRefundController;
use App\Http\Controllers\Dashboard\DashboardRoutingRuleController;
use App\Http\Controllers\Dashboard\DashboardWebhookEventController;
use App\Http\Controllers\Dashboard\DisputeController;
use App\Http\Controllers\Dashboard\EventLogController;
use App\Http\Controllers\Dashboard\NotificationController;
use App\Http\Controllers\Dashboard\SavedFilterController;
use App\Http\Controllers\Dashboard\TestPaymentController;
use App\Http\Controllers\Dashboard\UserRoleController;
use App\Http\Controllers\Dashboard\UserSettingsController;
use App\Http\Controllers\TestPspController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Dashboard SPA — authenticated user
    Route::get('/user', [UserController::class, 'show'])->middleware('auth:sanctum');
});

// Dashboard API — Sanctum auth only (no merchant context required)
Route::prefix('v1/dashboard')->middleware(['auth:sanctum', 'throttle:300,1'])->group(function () {
    // Organizations (Story 15-1)
    Route::get('/organizations', [DashboardOrganizationController::class, 'index']);
    Route::post('/organizations', [DashboardOrganizationController::class, 'store']);
    Route::get('/organizations/{orgKey}', [DashboardOrganizationController::class, 'show']);
    Route::get('/organizations/{orgKey}/merchants', [DashboardOrganizationController::class, 'merchants']);
    Route::patch('/organizations/{orgKey}', [DashboardOrganizationController::class, 'update']);
    Route::delete('/organizations/{orgKey}', [DashboardOrganizationController::class, 'destroy']);

    // Merchants
    Route::get('/merchants', [DashboardMerchantController::class, 'index']);
    Route::get('/merchants/{merchantKey}', [DashboardMerchantController::class, 'show']);
    Route::post('/merchants', [DashboardMerchantController::class, 'store']);
    Route::patch('/merchants/{merchantKey}', [DashboardMerchantController::class, 'update']);
    Route::delete('/merchants/{merchantKey}', [DashboardMerchantController::class, 'destroy']);

    // Profiles by merchant key (context switcher)
    Route::get('/merchants/{merchantKey}/profiles', [DashboardBusinessProfileController::class, 'indexByMerchant']);

    // Notifications (Story 15-4) — unread-count BEFORE wildcard
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::patch('/notifications/{notificationKey}/read', [NotificationController::class, 'markRead']);
    Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllRead']);
    Route::delete('/notifications/{notificationKey}', [NotificationController::class, 'destroy']);

    // User settings (Story 16-4)
    Route::get('/settings', [UserSettingsController::class, 'show']);
    Route::patch('/settings', [UserSettingsController::class, 'update']);

    // Saved filters (Story 16-5)
    Route::get('/saved-filters', [SavedFilterController::class, 'index']);
    Route::post('/saved-filters', [SavedFilterController::class, 'store']);
    Route::delete('/saved-filters/{filterId}', [SavedFilterController::class, 'destroy']);

    // Audit log (Story 15-3)
    Route::get('/audit-log', [AuditLogController::class, 'index']);
    Route::get('/audit-log/export', [AuditLogController::class, 'export']);

});

// Dashboard API — Sanctum auth + merchant context
Route::prefix('v1/dashboard')->middleware(['auth:sanctum', 'resolve.merchant', 'throttle:300,1'])->group(function () {
    // Analytics (Story 12-1)
    Route::get('/analytics/overview', [AnalyticsController::class, 'overview']);
    Route::get('/analytics/charts', [AnalyticsController::class, 'charts']);
    Route::get('/analytics/funnel', [AnalyticsController::class, 'funnel']);
    Route::get('/analytics/payment-methods', [AnalyticsController::class, 'paymentMethods']);
    Route::get('/analytics/failure-reasons', [AnalyticsController::class, 'failureReasons']);

    // Payments (Story 12-3, 12-5)
    Route::get('/payments', [DashboardPaymentController::class, 'index']);
    Route::get('/payments/export', [DashboardPaymentController::class, 'export']);
    Route::get('/payments/{paymentKey}', [DashboardPaymentController::class, 'show']);

    // Refunds (Story 12-6)
    Route::get('/refunds', [DashboardRefundController::class, 'index']);

    // Customers (Story 13-1)
    Route::get('/customers', [DashboardCustomerController::class, 'index']);
    Route::post('/customers', [DashboardCustomerController::class, 'store']);
    Route::get('/customers/{customerKey}', [DashboardCustomerController::class, 'show']);
    Route::patch('/customers/{customerKey}', [DashboardCustomerController::class, 'update']);
    Route::delete('/customers/{customerKey}', [DashboardCustomerController::class, 'destroy']);

    // Connectors (Story 13-2)
    Route::get('/connectors', [DashboardConnectorController::class, 'index']);
    Route::post('/connectors', [DashboardConnectorController::class, 'store']);
    Route::get('/connectors/{connectorKey}', [DashboardConnectorController::class, 'show']);
    Route::patch('/connectors/{connectorKey}', [DashboardConnectorController::class, 'update']);
    Route::delete('/connectors/{connectorKey}', [DashboardConnectorController::class, 'destroy']);
    Route::post('/connectors/{connectorKey}/test', [DashboardConnectorController::class, 'testConnection']);

    // Connector health (Story 16-2)
    Route::get('/connectors/{connectorKey}/health', [ConnectorHealthController::class, 'health']);
    Route::get('/connectors/{connectorKey}/health/errors', [ConnectorHealthController::class, 'errors']);

    // Routing rules (Story 13-3)
    Route::get('/routing-rules', [DashboardRoutingRuleController::class, 'index']);
    Route::post('/routing-rules', [DashboardRoutingRuleController::class, 'store']);
    Route::get('/routing-rules/{ruleKey}', [DashboardRoutingRuleController::class, 'show']);
    Route::patch('/routing-rules/{ruleKey}', [DashboardRoutingRuleController::class, 'update']);
    Route::delete('/routing-rules/{ruleKey}', [DashboardRoutingRuleController::class, 'destroy']);

    // API keys (Story 13-4)
    Route::get('/api-keys', [DashboardApiKeyController::class, 'index']);
    Route::post('/api-keys', [DashboardApiKeyController::class, 'store']);
    Route::delete('/api-keys/{keyId}', [DashboardApiKeyController::class, 'destroy']);

    // Webhook events (Story 13-5)
    Route::get('/webhook-events', [DashboardWebhookEventController::class, 'index']);
    Route::post('/webhook-events/{eventKey}/retry', [DashboardWebhookEventController::class, 'retry']);

    // Test payments (Story 14-2)
    Route::post('/test-payments', [TestPaymentController::class, 'store']);
    Route::post('/test-payments/create-only', [TestPaymentController::class, 'createOnly']);

    // Event logs (Story 14-3)
    Route::get('/event-logs', [EventLogController::class, 'index']);

    // Business profiles (Story 15-2)
    Route::get('/profiles', [DashboardBusinessProfileController::class, 'index']);
    Route::post('/profiles', [DashboardBusinessProfileController::class, 'store']);
    Route::get('/profiles/{profileKey}', [DashboardBusinessProfileController::class, 'show']);
    Route::patch('/profiles/{profileKey}', [DashboardBusinessProfileController::class, 'update']);
    Route::delete('/profiles/{profileKey}', [DashboardBusinessProfileController::class, 'destroy']);

    // Disputes (Story 16-1)
    Route::get('/disputes', [DisputeController::class, 'index']);
    Route::get('/disputes/{disputeKey}', [DisputeController::class, 'show']);
    Route::post('/disputes/{disputeKey}/evidence', [DisputeController::class, 'submitEvidence']);

    // Users & RBAC (Story 16-3)
    Route::get('/users', [UserRoleController::class, 'index']);
    Route::post('/users/roles', [UserRoleController::class, 'store']);
    Route::patch('/users/roles/{roleId}', [UserRoleController::class, 'update']);
    Route::delete('/users/roles/{roleId}', [UserRoleController::class, 'destroy']);
});

// Test PSP simulator — no auth, no middleware
Route::prefix('v1')->group(function () {
    Route::get('/test-psp/{paymentKey}', [TestPspController::class, 'show']);
    Route::post('/test-psp/{paymentKey}/complete', [TestPspController::class, 'complete']);
});

Route::prefix('v1')->middleware('json-api')->group(function () {
    // Incoming PSP webhooks (no auth)
    Route::post('/webhooks/{merchantKey}/{mcaKey}', [WebhookReceiverController::class, 'handle'])
        ->withoutMiddleware(['auth.api_key']);

    Route::get('/health', function () {
        try {
            DB::connection()->getPdo();
            $dbStatus = 'connected';
        } catch (Throwable) {
            $dbStatus = 'disconnected';
        }

        return response()->json([
            'status' => $dbStatus === 'connected' ? 'healthy' : 'unhealthy',
            'timestamp' => now()->toIso8601String(),
            'database' => $dbStatus,
        ], $dbStatus === 'connected' ? 200 : 503);
    });

    // Admin API
    Route::middleware(['auth.api_key', 'auth.admin_api_key', 'throttle:payswitch-api'])->group(function () {
        Route::post('/organizations', [Admin\OrganizationController::class, 'store']);
        Route::post('/merchants', [Admin\MerchantAccountController::class, 'store']);
        Route::get('/merchants/{merchantKey}', [Admin\MerchantAccountController::class, 'show']);
        Route::post('/profiles', [Admin\BusinessProfileController::class, 'store']);
        Route::get('/profiles/{profileKey}', [Admin\BusinessProfileController::class, 'show']);
        Route::post('/merchants/{merchantKey}/api-keys', [Admin\ApiKeyController::class, 'store']);
        Route::delete('/merchants/{merchantKey}/api-keys/{keyId}', [Admin\ApiKeyController::class, 'destroy']);
        Route::post('/merchants/{merchantKey}/connectors', [Admin\ConnectorController::class, 'store']);
        Route::get('/merchants/{merchantKey}/connectors', [Admin\ConnectorController::class, 'index']);
        Route::get('/merchants/{merchantKey}/connectors/{connectorKey}', [Admin\ConnectorController::class, 'show']);
        Route::patch('/merchants/{merchantKey}/connectors/{connectorKey}', [Admin\ConnectorController::class, 'update']);
        Route::delete('/merchants/{merchantKey}/connectors/{connectorKey}', [Admin\ConnectorController::class, 'destroy']);

        Route::post('/merchants/{merchantKey}/routing-rules', [Admin\RoutingRuleController::class, 'store']);
        Route::get('/merchants/{merchantKey}/routing-rules', [Admin\RoutingRuleController::class, 'index']);
        Route::get('/merchants/{merchantKey}/routing-rules/{ruleKey}', [Admin\RoutingRuleController::class, 'show']);
        Route::patch('/merchants/{merchantKey}/routing-rules/{ruleKey}', [Admin\RoutingRuleController::class, 'update']);
        Route::delete('/merchants/{merchantKey}/routing-rules/{ruleKey}', [Admin\RoutingRuleController::class, 'destroy']);
    });

    // Public API — authenticated via publishable_key + client_secret
    Route::middleware(['auth.api_key', 'auth.client_secret', 'throttle:payswitch-api'])->group(function () {
        Route::get('/payments/{paymentKey}', [PublicPaymentController::class, 'show'])->name('api.v1.public.payments.show');
        Route::post('/payments/{paymentKey}/confirm', [PublicPaymentController::class, 'confirm'])->name('api.v1.public.payments.confirm');
        Route::get('/payments/{paymentKey}/payment-methods', [PublicPaymentController::class, 'paymentMethods'])->name('api.v1.public.payments.payment-methods');
        Route::get('/payments/{paymentKey}/status', PublicPaymentStatusController::class)->name('api.v1.public.payments.status');
    });

    // Merchant API
    Route::middleware(['auth.api_key', 'auth.secret_api_key', 'throttle:payswitch-api'])->group(function () {
        Route::post('/payments', [PaymentController::class, 'store'])->name('api.v1.payments.store');
        Route::post('/payments/{paymentKey}/capture', [PaymentController::class, 'capture']);
        Route::post('/payments/{paymentKey}/cancel', [PaymentController::class, 'cancel']);
        Route::post('/payments/{paymentKey}/sync', [PaymentController::class, 'sync']);

        Route::post('/refunds', [RefundController::class, 'store']);
        Route::get('/refunds/{refundKey}', [RefundController::class, 'show']);

        Route::post('/customers', [CustomerController::class, 'store']);
        Route::get('/customers', [CustomerController::class, 'index']);
        Route::get('/customers/{customerKey}', [CustomerController::class, 'show']);
        Route::patch('/customers/{customerKey}', [CustomerController::class, 'update']);
        Route::delete('/customers/{customerKey}', [CustomerController::class, 'destroy']);

        Route::post('/customers/{customerKey}/payment-methods', [PaymentMethodController::class, 'store']);
        Route::get('/customers/{customerKey}/payment-methods', [PaymentMethodController::class, 'index']);
        Route::get('/payment-methods/{pmKey}', [PaymentMethodController::class, 'show']);
        Route::delete('/payment-methods/{pmKey}', [PaymentMethodController::class, 'destroy']);
        Route::post('/payment-methods/{pmKey}/default', [PaymentMethodController::class, 'setDefault']);
    });
});
