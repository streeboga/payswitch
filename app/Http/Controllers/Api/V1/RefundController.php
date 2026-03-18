<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\Refund\StoreRefundRequest;
use App\Http\Resources\RefundResource;
use App\Services\RefundService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

#[Group(name: 'Refunds', weight: 3)]
final class RefundController extends Controller
{
    public function __construct(
        private readonly RefundService $refundService,
    ) {}

    /**
     * Create a refund.
     *
     * Initiates a refund against a previously succeeded payment intent. Partial refunds supported.
     */
    public function store(StoreRefundRequest $request): JsonResponse
    {
        $merchantAccountId = $request->attributes->get('merchant_id');

        $refund = $this->refundService->create($request->toDto(), $merchantAccountId);

        return (new RefundResource($refund))
            ->withStatus(201)
            ->withHeader('Location', url("/api/v1/refunds/{$refund->key}"))
            ->toResponse($request);
    }

    /**
     * Get a refund.
     *
     * Retrieves the details of a refund including its current processing status.
     */
    public function show(string $refundKey, Request $request): JsonResponse
    {
        $merchantAccountId = $request->attributes->get('merchant_id');
        $refund = $this->refundService->find($refundKey, $merchantAccountId);

        return (new RefundResource($refund))->toResponse($request);
    }
}
