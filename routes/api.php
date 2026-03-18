<?php

use App\Http\Controllers\Api\V1\Admin;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PaymentMethodController;
use App\Http\Controllers\Api\V1\RefundController;
use App\Http\Controllers\Api\V1\WebhookReceiverController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

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

    // Merchant API
    Route::middleware(['auth.api_key', 'auth.secret_api_key', 'throttle:payswitch-api'])->group(function () {
        Route::post('/payments', [PaymentController::class, 'store'])->name('api.v1.payments.store');
        Route::get('/payments/{paymentKey}', [PaymentController::class, 'show'])->name('api.v1.payments.show');
        Route::post('/payments/{paymentKey}/confirm', [PaymentController::class, 'confirm']);
        Route::post('/payments/{paymentKey}/capture', [PaymentController::class, 'capture']);
        Route::post('/payments/{paymentKey}/cancel', [PaymentController::class, 'cancel']);

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
