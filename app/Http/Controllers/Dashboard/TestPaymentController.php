<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentIntentResource;
use App\Services\TestPaymentService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
    public function store(Request $request): JsonResponse
    {
        $merchantId = $request->attributes->get('merchant_id');

        $validated = $request->validate([
            'data.attributes.amount' => 'required|integer|min:1',
            'data.attributes.currency' => 'sometimes|string|size:3',
            'data.attributes.capture_method' => 'sometimes|string|in:automatic,manual',
            'data.attributes.payment_method' => 'sometimes|string',
            'data.attributes.card_number' => 'sometimes|string',
            'data.attributes.payment_method_data' => 'sometimes|array',
        ]);

        $payment = $this->testPaymentService->createAndConfirm(
            $validated['data']['attributes'],
            $merchantId,
        );

        return (new PaymentIntentResource($payment))
            ->withStatus(201)
            ->toResponse($request);
    }
}
