<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Resources\DisputeResource;
use App\Models\Dispute;
use App\Models\DisputeEvidence;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Dashboard Disputes', description: 'Dispute management for the dashboard', weight: 23)]
final class DisputeController extends Controller
{
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
        $query = Dispute::where('merchant_account_id', $merchantId);

        if ($status = $request->input('filter.status')) {
            $query->where('status', $status);
        }
        if ($type = $request->input('filter.type')) {
            $query->where('type', $type);
        }

        $perPage = min((int) $request->input('page.size', 20), 100);
        $paginator = $query->orderByDesc('created_at')->paginate($perPage);

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

        $dispute = Dispute::where('merchant_account_id', $merchantId)
            ->where('key', $disputeKey)
            ->firstOrFail();

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
    public function submitEvidence(string $disputeKey, Request $request): JsonResponse
    {
        $merchantId = $request->attributes->get('merchant_id');

        $dispute = Dispute::where('merchant_account_id', $merchantId)
            ->where('key', $disputeKey)
            ->firstOrFail();

        $validated = $request->validate([
            'data.attributes.type' => 'required|string',
            'data.attributes.text_content' => 'sometimes|string',
            'data.attributes.file' => 'sometimes|file|max:10240',
        ]);

        $attrs = $validated['data']['attributes'];
        $filePath = null;

        if ($request->hasFile('data.attributes.file')) {
            $filePath = $request->file('data.attributes.file')->store("disputes/{$dispute->key}", 'local');
        }

        $evidence = DisputeEvidence::create([
            'dispute_id' => $dispute->id,
            'type' => $attrs['type'],
            'text_content' => $attrs['text_content'] ?? null,
            'file_path' => $filePath,
        ]);

        return response()->json([
            'data' => [
                'type' => 'dispute-evidences',
                'id' => (string) $evidence->id,
                'attributes' => [
                    'type' => $evidence->type,
                    'file_path' => $evidence->file_path,
                    'text_content' => $evidence->text_content,
                    'created_at' => $evidence->created_at->toIso8601String(),
                ],
            ],
        ], 201, ['Content-Type' => 'application/vnd.api+json']);
    }
}
