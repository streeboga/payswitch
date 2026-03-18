<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\RefundListRequest;
use App\Http\Resources\RefundResource;
use App\Services\DashboardRefundService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

#[Group('Dashboard Refunds', description: 'Refund list for the dashboard', weight: 11)]
final class DashboardRefundController extends Controller
{
    public function __construct(
        private readonly DashboardRefundService $refundService,
    ) {}

    /**
     * List refunds
     *
     * Retrieve a paginated list of refunds for the current merchant.
     * Supports filtering by status, date range, and free-text search.
     */
    #[QueryParameter('filter[status]', type: 'string', description: 'Filter by refund status', example: 'succeeded')]
    #[QueryParameter('filter[from]', type: 'string', description: 'Start date (YYYY-MM-DD)', example: '2026-01-01')]
    #[QueryParameter('filter[to]', type: 'string', description: 'End date (YYYY-MM-DD)', example: '2026-03-18')]
    #[QueryParameter('filter[search]', type: 'string', description: 'Free-text search by key, reason, error message')]
    #[QueryParameter('sort', type: 'string', description: 'Sort field (- for DESC)', example: '-created_at')]
    #[QueryParameter('page[size]', type: 'integer', description: 'Items per page (max 100)', example: 20)]
    #[QueryParameter('page[number]', type: 'integer', description: 'Page number', example: 1)]
    #[Response(200, description: 'Paginated refund list')]
    #[Response(401, description: 'Unauthenticated')]
    #[Response(404, description: 'Merchant not found')]
    public function index(RefundListRequest $request): JsonResponse
    {
        $merchantId = $request->attributes->get('merchant_id');
        $filters = array_filter([...$request->filters(), 'sort' => $request->sortParam()]);

        return RefundResource::jsonApiCollection(
            $this->refundService->list($merchantId, $filters, $request->perPage()),
            $request,
        );
    }
}
