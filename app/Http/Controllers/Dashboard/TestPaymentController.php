<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\DataTransferObjects\Payment\CreatePaymentData;
use App\Http\Requests\Dashboard\StoreTestPaymentRequest;
use App\Http\Resources\PaymentIntentResource;
use App\Services\PaymentService;
use App\Services\TestPaymentService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

#[Group('Dashboard Test Payments', description: 'Create test payments for development', weight: 17)]
final class TestPaymentController extends Controller
{
    public function __construct(
        private readonly TestPaymentService $testPaymentService,
        private readonly PaymentService $paymentService,
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

    /**
     * Create payment without confirming
     *
     * Creates a payment intent and returns client_secret for widget preview.
     * Does not confirm or charge — the widget handles the checkout flow.
     */
    #[Response(201, description: 'Payment created')]
    #[Response(422, description: 'Validation error')]
    public function createOnly(Request $request): JsonResponse
    {
        $merchantId = $request->attributes->get('merchant_id');
        Gate::authorize('test-payment.create', [$merchantId]);

        $request->validate([
            'amount' => ['required', 'integer', 'min:100'],
            'currency' => ['required', 'string', 'size:3'],
            'connector_name' => ['sometimes', 'string'],
        ]);

        $frontendUrl = rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/');

        $dto = CreatePaymentData::from([
            'amount' => $request->integer('amount'),
            'currency' => $request->string('currency')->toString(),
            'return_url' => $frontendUrl.'/payments',
        ]);

        $payment = $this->paymentService->create($dto, $merchantId);

        // Update return_url with actual payment key so test-psp redirects to payment detail
        $payment->update(['return_url' => $frontendUrl.'/payments/'.$payment->key]);

        return (new PaymentIntentResource($payment))
            ->withStatus(201)
            ->toResponse($request);
    }
}
