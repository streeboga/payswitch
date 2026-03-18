<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\AnalyticsRequest;
use App\Http\Resources\AnalyticsResource;
use App\Services\AnalyticsService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

#[Group('Dashboard Analytics', description: 'Analytics and reporting endpoints for the dashboard', weight: 9)]
final class AnalyticsController extends Controller
{
    public function __construct(
        private readonly AnalyticsService $analyticsService,
    ) {}

    /**
     * Get analytics overview
     *
     * Aggregated payment and refund totals for the selected period.
     */
    #[QueryParameter('filter[period]', type: 'string', description: 'Preset period', enum: ['7d', '30d', '90d'])]
    #[QueryParameter('filter[from]', type: 'string', description: 'Start date (YYYY-MM-DD)', example: '2026-01-01')]
    #[QueryParameter('filter[to]', type: 'string', description: 'End date (YYYY-MM-DD)', example: '2026-03-18')]
    #[Response(200, description: 'Analytics overview')]
    #[Response(401, description: 'Unauthenticated')]
    public function overview(AnalyticsRequest $request): JsonResponse
    {
        Gate::authorize('analytics.viewAny', [$request->merchantId()]);
        $data = $this->analyticsService->overview($request->merchantId(), $request->toPeriodFilter());

        return (new AnalyticsResource($data, 'analytics-overview'))->toResponse($request);
    }

    /**
     * Get daily charts data
     *
     * Daily breakdown of payment counts and amounts for chart rendering.
     */
    #[Response(200, description: 'Daily chart data points')]
    public function charts(AnalyticsRequest $request): JsonResponse
    {
        Gate::authorize('analytics.viewAny', [$request->merchantId()]);
        $data = $this->analyticsService->charts($request->merchantId(), $request->toPeriodFilter());

        return AnalyticsResource::jsonApiListFromArray('analytics-charts', $data, $request);
    }

    /**
     * Get payment funnel
     *
     * Conversion funnel stages: created → confirmed → authorized → captured.
     */
    #[Response(200, description: 'Funnel stage counts')]
    public function funnel(AnalyticsRequest $request): JsonResponse
    {
        Gate::authorize('analytics.viewAny', [$request->merchantId()]);
        $data = $this->analyticsService->funnel($request->merchantId(), $request->toPeriodFilter());

        return (new AnalyticsResource($data, 'analytics-funnel'))->toResponse($request);
    }

    /**
     * Get payment methods breakdown
     *
     * Distribution of successful payments across connectors.
     */
    #[Response(200, description: 'Payment method breakdown')]
    public function paymentMethods(AnalyticsRequest $request): JsonResponse
    {
        Gate::authorize('analytics.viewAny', [$request->merchantId()]);
        $data = $this->analyticsService->paymentMethods($request->merchantId(), $request->toPeriodFilter());

        return AnalyticsResource::jsonApiListFromArray('analytics-payment-methods', $data, $request);
    }

    /**
     * Get failure reasons
     *
     * Top error codes and messages for failed payments.
     */
    #[Response(200, description: 'Failure reason breakdown')]
    public function failureReasons(AnalyticsRequest $request): JsonResponse
    {
        Gate::authorize('analytics.viewAny', [$request->merchantId()]);
        $data = $this->analyticsService->failureReasons($request->merchantId(), $request->toPeriodFilter());

        return AnalyticsResource::jsonApiListFromArray('analytics-failure-reasons', $data, $request);
    }
}
