<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\PaymentMethod\StorePaymentMethodRequest;
use App\Http\Resources\PaymentMethodResource;
use App\Services\PaymentMethodService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

#[Group(name: 'Payment Methods', description: 'Manage saved payment methods for customers', weight: 4)]
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
    #[PathParameter('customerKey', description: 'Customer public key', example: 'cus_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(201, description: 'Payment method created')]
    #[Response(422, description: 'Validation error')]
    public function store(string $customerKey, StorePaymentMethodRequest $request): JsonResponse
    {
        $merchantAccountId = $request->attributes->get('merchant_id');

        $pm = $this->paymentMethodService->create($request->toDto(), $customerKey, $merchantAccountId);

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
    #[PathParameter('customerKey', description: 'Customer public key', example: 'cus_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(200, description: 'Payment method list')]
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
    #[PathParameter('pmKey', description: 'Payment method public key', example: 'pm_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(200, description: 'Payment method details')]
    #[Response(404, description: 'Payment method not found')]
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
    #[PathParameter('pmKey', description: 'Payment method public key', example: 'pm_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(204, description: 'Payment method deleted')]
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
    #[PathParameter('pmKey', description: 'Payment method public key', example: 'pm_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(200, description: 'Default payment method set')]
    #[Response(404, description: 'Payment method not found')]
    public function setDefault(string $pmKey, Request $request): JsonResponse
    {
        $merchantAccountId = $request->attributes->get('merchant_id');
        $pm = $this->paymentMethodService->setDefault($pmKey, $merchantAccountId);

        return (new PaymentMethodResource($pm))->toResponse($request);
    }
}
