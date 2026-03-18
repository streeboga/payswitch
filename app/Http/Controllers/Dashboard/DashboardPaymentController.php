<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\PaymentListRequest;
use App\Http\Resources\PaymentIntentResource;
use App\Repositories\Contracts\PaymentIntentRepositoryInterface;
use App\Services\DashboardPaymentService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Group('Dashboard Payments', description: 'Payment list and export for the dashboard', weight: 10)]
final class DashboardPaymentController extends Controller
{
    public function __construct(
        private readonly DashboardPaymentService $paymentService,
        private readonly PaymentIntentRepositoryInterface $paymentRepository,
    ) {}

    /**
     * List payments
     *
     * Retrieve a paginated list of payments for the current merchant.
     * Supports filtering by status, currency, connector, amount range, date range, and free-text search.
     */
    #[QueryParameter('filter[status]', type: 'string', description: 'Filter by payment status', example: 'succeeded')]
    #[QueryParameter('filter[currency]', type: 'string', description: 'Filter by currency (ISO 4217)', example: 'USD')]
    #[QueryParameter('filter[connector]', type: 'string', description: 'Filter by connector name')]
    #[QueryParameter('filter[capture_method]', type: 'string', description: 'Filter by capture method', enum: ['automatic', 'manual'])]
    #[QueryParameter('filter[amount_min]', type: 'integer', description: 'Minimum amount filter')]
    #[QueryParameter('filter[amount_max]', type: 'integer', description: 'Maximum amount filter')]
    #[QueryParameter('filter[from]', type: 'string', description: 'Start date (YYYY-MM-DD)', example: '2026-01-01')]
    #[QueryParameter('filter[to]', type: 'string', description: 'End date (YYYY-MM-DD)', example: '2026-03-18')]
    #[QueryParameter('filter[search]', type: 'string', description: 'Free-text search by key, description, error message')]
    #[QueryParameter('sort', type: 'string', description: 'Sort field (- for DESC)', example: '-created_at')]
    #[QueryParameter('page[size]', type: 'integer', description: 'Items per page (max 100)', example: 20)]
    #[QueryParameter('page[number]', type: 'integer', description: 'Page number', example: 1)]
    #[Response(200, description: 'Paginated payment list')]
    #[Response(401, description: 'Unauthenticated')]
    #[Response(404, description: 'Merchant not found')]
    public function index(PaymentListRequest $request): JsonResponse
    {
        $merchantId = $request->attributes->get('merchant_id');
        $filters = array_filter([...$request->filters(), 'sort' => $request->sortParam()]);

        return PaymentIntentResource::jsonApiCollection(
            $this->paymentService->list($merchantId, $filters, $request->perPage()),
            $request,
        );
    }

    /**
     * Get payment detail
     *
     * Retrieve a single payment with full details.
     */
    #[PathParameter('paymentKey', description: 'Payment public key', example: 'pay_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(200, description: 'Payment details')]
    #[Response(404, description: 'Payment not found')]
    public function show(string $paymentKey, Request $request): JsonResponse
    {
        $merchantId = $request->attributes->get('merchant_id');
        $payment = $this->paymentRepository->findByKey($paymentKey, $merchantId);

        return (new PaymentIntentResource($payment))->toResponse($request);
    }

    /**
     * Export payments
     *
     * Stream a CSV export of payments matching the given filters.
     */
    #[Response(200, description: 'CSV file stream')]
    #[Response(401, description: 'Unauthenticated')]
    public function export(PaymentListRequest $request): StreamedResponse
    {
        $merchantId = $request->attributes->get('merchant_id');
        $filters = array_filter([...$request->filters(), 'sort' => $request->sortParam()]);

        $cursor = $this->paymentService->exportCursor($merchantId, $filters);

        return response()->streamDownload(function () use ($cursor) {
            $out = fopen('php://output', 'w');

            fputcsv($out, ['id', 'status', 'amount', 'currency', 'connector', 'description', 'error_code', 'created_at']);

            foreach ($cursor as $payment) {
                fputcsv($out, [
                    $payment->key,
                    $payment->status->value,
                    $payment->amount,
                    $payment->currency,
                    $payment->connector,
                    $payment->description,
                    $payment->error_code,
                    $payment->created_at->toIso8601String(),
                ]);
            }

            fclose($out);
        }, 'payments-export.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }
}
