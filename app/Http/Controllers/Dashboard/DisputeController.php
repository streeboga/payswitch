<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\SubmitDisputeEvidenceRequest;
use App\Http\Resources\DisputeEvidenceResource;
use App\Http\Resources\DisputeResource;
use App\Services\DisputeService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

#[Group('Dashboard Disputes', description: 'Dispute management for the dashboard', weight: 23)]
final class DisputeController extends Controller
{
    public function __construct(
        private readonly DisputeService $disputeService,
    ) {}

    /**
     * List disputes
     *
     * Retrieve disputes for the current merchant.
     */
    #[QueryParameter('filter[status]', type: 'string', description: 'Filter by dispute status')]
    #[QueryParameter('filter[type]', type: 'string', description: 'Filter by dispute type')]
    #[QueryParameter('page[size]', type: 'integer', description: 'Items per page', example: 20)]
    #[Response(200, description: 'Paginated dispute list')]
    public function index(Request $request): JsonResponse
    {
        $merchantId = $request->attributes->get('merchant_id');
        Gate::authorize('dispute.viewAny', [$merchantId]);

        $paginator = $this->disputeService->list(
            merchantAccountId: $merchantId,
            status: $request->input('filter.status'),
            type: $request->input('filter.type'),
            perPage: (int) $request->input('page.size', 20),
        );

        return DisputeResource::jsonApiCollection($paginator, $request);
    }

    /**
     * Get dispute
     *
     * Retrieve a single dispute with details.
     */
    #[PathParameter('disputeKey', description: 'Dispute public key', example: 'dsp_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(200, description: 'Dispute details')]
    #[Response(404, description: 'Dispute not found')]
    public function show(string $disputeKey, Request $request): JsonResponse
    {
        $merchantId = $request->attributes->get('merchant_id');
        Gate::authorize('dispute.view', [$merchantId]);
        $dispute = $this->disputeService->find($disputeKey, $merchantId);

        return (new DisputeResource($dispute))->toResponse($request);
    }

    /**
     * Submit dispute evidence
     *
     * Upload evidence for a dispute.
     */
    #[PathParameter('disputeKey', description: 'Dispute public key')]
    #[Response(201, description: 'Evidence submitted')]
    #[Response(404, description: 'Dispute not found')]
    public function submitEvidence(string $disputeKey, SubmitDisputeEvidenceRequest $request): JsonResponse
    {
        $merchantId = $request->attributes->get('merchant_id');
        Gate::authorize('dispute.submitEvidence', [$merchantId]);
        $dispute = $this->disputeService->find($disputeKey, $merchantId);

        $validated = $request->validated();

        $attrs = $validated;
        $filePath = null;

        if ($request->hasFile('file')) {
            $stored = $request->file('file')->store("disputes/{$dispute->key}", 'local');
            $filePath = $stored !== false ? $stored : null;
        }

        $evidence = $this->disputeService->createEvidence($dispute, $attrs, $filePath);

        return (new DisputeEvidenceResource($evidence))
            ->withStatus(201)
            ->toResponse($request);
    }
}
