<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Resources\WebhookEventResource;
use App\Jobs\DeliverWebhookJob;
use App\Repositories\Contracts\WebhookEventRepositoryInterface;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Dashboard Webhook Events', description: 'Webhook event monitoring and retry', weight: 16)]
final class DashboardWebhookEventController extends Controller
{
    public function __construct(
        private readonly WebhookEventRepositoryInterface $webhookEventRepository,
    ) {}

    /**
     * List webhook events
     *
     * Retrieve webhook events for the current merchant with optional filters.
     */
    #[QueryParameter('filter[status]', type: 'string', description: 'Filter by delivery status', enum: ['delivered', 'failed', 'pending'])]
    #[QueryParameter('filter[event_type]', type: 'string', description: 'Filter by event type')]
    #[QueryParameter('page[size]', type: 'integer', description: 'Items per page', example: 20)]
    #[QueryParameter('page[number]', type: 'integer', description: 'Page number', example: 1)]
    #[Response(200, description: 'Paginated webhook event list')]
    public function index(Request $request): JsonResponse
    {
        $merchantId = $request->attributes->get('merchant_id');

        $paginator = $this->webhookEventRepository->paginateForMerchant($merchantId, [
            'status' => $request->input('filter.status'),
            'event_type' => $request->input('filter.event_type'),
        ], (int) $request->input('page.size', 20));

        return WebhookEventResource::jsonApiCollection($paginator, $request);
    }

    /**
     * Retry webhook delivery
     *
     * Manually retry delivering a failed webhook event.
     */
    #[PathParameter('eventKey', description: 'Webhook event public key', example: 'evt_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(202, description: 'Retry dispatched')]
    #[Response(404, description: 'Webhook event not found')]
    public function retry(string $eventKey, Request $request): JsonResponse
    {
        $merchantId = $request->attributes->get('merchant_id');
        $event = $this->webhookEventRepository->findByKeyForMerchant($eventKey, $merchantId);

        DeliverWebhookJob::dispatch($event->id);

        return response()->json([
            'data' => [
                'type' => 'webhook-retries',
                'id' => $event->key,
                'attributes' => ['status' => 'dispatched'],
            ],
        ], 202, ['Content-Type' => 'application/vnd.api+json']);
    }
}
