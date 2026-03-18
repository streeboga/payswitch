<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\PaymentMethod\StorePaymentMethodRequest;
use App\Http\Resources\PaymentMethodResource;
use App\Services\PaymentMethodService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

#[Group(name: 'Payment Methods', weight: 4)]
final class PaymentMethodController extends Controller
{
    public function __construct(
        private readonly PaymentMethodService $paymentMethodService,
    ) {}

    /**
     * Create a payment method.
     *
     * Attaches a new payment method to an existing customer.
     */
    public function store(string $customerKey, StorePaymentMethodRequest $request): JsonResponse
    {
        $merchantAccountId = $request->attributes->get('merchant_id');
        $attributes = $request->validatedAttributes();

        $pm = $this->paymentMethodService->create($attributes, $customerKey, $merchantAccountId);

        return (new PaymentMethodResource($pm))
            ->withStatus(201)
            ->withHeader('Location', url("/api/v1/payment-methods/{$pm->key}"))
            ->toResponse($request);
    }

    /**
     * List payment methods.
     *
     * Returns all payment methods belonging to a specific customer.
     */
    public function index(string $customerKey, Request $request): JsonResponse
    {
        $merchantAccountId = $request->attributes->get('merchant_id');
        $methods = $this->paymentMethodService->listForCustomer($customerKey, $merchantAccountId);

        return PaymentMethodResource::jsonApiList($methods, $request);
    }

    /**
     * Get a payment method.
     *
     * Retrieves the details of a specific payment method.
     */
    public function show(string $pmKey, Request $request): JsonResponse
    {
        $merchantAccountId = $request->attributes->get('merchant_id');
        $pm = $this->paymentMethodService->find($pmKey, $merchantAccountId);

        return (new PaymentMethodResource($pm))->toResponse($request);
    }

    /**
     * Delete a payment method.
     *
     * Permanently removes a payment method from the customer.
     */
    public function destroy(string $pmKey, Request $request): JsonResponse
    {
        $merchantAccountId = $request->attributes->get('merchant_id');
        $this->paymentMethodService->delete($pmKey, $merchantAccountId);

        return response()->json(null, 204);
    }

    /**
     * Set default payment method.
     *
     * Marks the specified payment method as the default for its customer.
     */
    public function setDefault(string $pmKey, Request $request): JsonResponse
    {
        $merchantAccountId = $request->attributes->get('merchant_id');
        $pm = $this->paymentMethodService->setDefault($pmKey, $merchantAccountId);

        return (new PaymentMethodResource($pm))->toResponse($request);
    }
}
