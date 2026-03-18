<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\JsonApiResponse;
use App\Services\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Streeboga\PaymentData\Models\Refund;

final class RefundController extends Controller
{
    use JsonApiResponse;

    public function __construct(
        private readonly RefundService $refundService,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'data.attributes.payment_id' => ['required', 'string'],
            'data.attributes.amount' => ['required', 'integer', 'min:1'],
        ]);

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
