<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\Refund\StoreRefundRequest;
use App\Http\Requests\Dashboard\RefundListRequest;
use App\Http\Resources\RefundResource;
use App\Services\DashboardRefundService;
use App\Services\RefundService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

#[Group(name: 'Refunds', description: 'Create and retrieve refunds', weight: 3)]
final class RefundController extends Controller
{
    public function __construct(
        private readonly RefundService $refundService,
        private readonly DashboardRefundService $listService,
    ) {}

    /**
     * List refunds.
     *
     * Refunds of the merchant the API key belongs to. filter[payment_id] takes the
     * public key of the payment: the card screen asks for refunds by that key.
     */
    #[QueryParameter('filter[payment_id]', type: 'string', description: 'Payment public key', example: 'pay_01jd5x7k3m9p2q4r6s8t0v')]
    #[QueryParameter('filter[status]', type: 'string', example: 'succeeded')]
    #[QueryParameter('filter[from]', type: 'string', example: '2026-01-01')]
    #[QueryParameter('filter[to]', type: 'string', example: '2026-03-18')]
    #[QueryParameter('page[size]', type: 'integer', example: 20)]
    #[QueryParameter('page[number]', type: 'integer', example: 1)]
    #[Response(200, description: 'Paginated refund list')]
    #[Response(401, description: 'Missing or invalid API key')]
    #[Response(403, description: 'Publishable key is not allowed here')]
    public function index(RefundListRequest $request): JsonResponse
    {
        $merchantId = $request->attributes->get('merchant_id');
        $filters = array_filter([...$request->filters(), 'sort' => $request->sortParam()]);

        return RefundResource::jsonApiCollection(
            $this->listService->list($merchantId, $filters, $request->perPage()),
            $request,
        );
    }

    /**
     * Create a refund.
     *
     * Initiates a refund against a previously succeeded payment intent. Partial refunds supported.
     */
    #[Response(201, description: 'Refund created')]
    #[Response(422, description: 'Validation error')]
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
    #[PathParameter('refundKey', description: 'Refund public key', example: 'ref_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(200, description: 'Refund details')]
    #[Response(404, description: 'Refund not found')]
    public function show(string $refundKey, Request $request): JsonResponse
    {
        $merchantAccountId = $request->attributes->get('merchant_id');
        $refund = $this->refundService->find($refundKey, $merchantAccountId);

        return (new RefundResource($refund))->toResponse($request);
    }
}
