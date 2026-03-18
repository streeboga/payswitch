<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\JsonApiResponse;
use App\Http\Requests\Api\Refund\StoreRefundRequest;
use App\Services\RefundService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Streeboga\PaymentData\Models\Refund;

#[Group(name: 'Refunds', weight: 3)]
final class RefundController extends Controller
{
    use JsonApiResponse;

    public function __construct(
        private readonly RefundService $refundService,
    ) {}

    /**
     * Create a refund.
     *
     * Initiates a refund against a previously succeeded payment intent. The refund amount must
     * not exceed the original captured amount. Partial refunds are supported by specifying
     * an amount less than the captured total.
     */
    public function store(StoreRefundRequest $request): JsonResponse
    {
        $attributes = $request->input('data.attributes', []);
        $merchantAccountId = $request->attributes->get('merchant_id');

        $refund = $this->refundService->create($attributes, $merchantAccountId);

        return $this->jsonApiResource(
            model: $refund,
            type: 'refunds',
            attributes: $this->refundAttributes($refund),
            status: 201,
            headers: ['Location' => url("/api/v1/refunds/{$refund->key}")],
        );
    }

    /**
     * Get a refund.
     *
     * Retrieves the details of a refund including its current processing status,
     * amount, and any error information from the payment connector.
     *
     * @pathParam refundKey string required The unique key of the refund. Example: ref_1a2b3c4d5e
     */
    public function show(string $refundKey, Request $request): JsonResponse
    {
        $merchantAccountId = $request->attributes->get('merchant_id');
        $refund = $this->refundService->find($refundKey, $merchantAccountId);

        return $this->jsonApiResource(
            model: $refund,
            type: 'refunds',
            attributes: $this->refundAttributes($refund),
        );
    }

    private function refundAttributes(Refund $refund): array
    {
        return [
            'payment_id' => $refund->paymentIntent?->key,
            'amount' => $refund->amount,
            'currency' => $refund->currency,
            'status' => $refund->status->value,
            'reason' => $refund->reason,
            'connector' => $refund->connector,
            'error_code' => $refund->error_code,
            'error_message' => $refund->error_message,
            'metadata' => $refund->metadata,
            'created_at' => $refund->created_at->toIso8601String(),
        ];
    }
}
