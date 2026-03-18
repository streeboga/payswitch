<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\StoreTestPaymentRequest;
use App\Http\Resources\PaymentIntentResource;
use App\Services\TestPaymentService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

#[Group('Dashboard Test Payments', description: 'Create test payments for development', weight: 17)]
final class TestPaymentController extends Controller
{
    public function __construct(
        private readonly TestPaymentService $testPaymentService,
    ) {}

    /**
     * Create test payment
     *
     * Create and confirm a test payment in one request. Useful for testing connector integrations.
     */
    #[Response(201, description: 'Test payment created')]
    #[Response(422, description: 'Validation error')]
    public function store(StoreTestPaymentRequest $request): JsonResponse
    {
        $merchantId = $request->attributes->get('merchant_id');
        Gate::authorize('test-payment.create', [$merchantId]);

        $validated = $request->validated();

        $payment = $this->testPaymentService->createAndConfirm(
            $validated,
            $merchantId,
        );

        return (new PaymentIntentResource($payment))
            ->withStatus(201)
            ->toResponse($request);
    }
}
