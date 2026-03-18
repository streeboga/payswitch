<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Requests\Api\Admin\StoreRoutingRuleRequest;
use App\Http\Requests\Api\Admin\UpdateRoutingRuleRequest;
use App\Http\Resources\RoutingRuleResource;
use App\Services\RoutingRuleService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

#[Group(name: 'Admin > Routing Rules', description: 'Payment routing rule management', weight: 15)]
final class RoutingRuleController extends Controller
{
    public function __construct(
        private readonly RoutingRuleService $routingRuleService,
    ) {}

    /**
     * Create a routing rule.
     *
     * Creates a new routing rule for the specified merchant account.
     */
    #[PathParameter('merchantKey', description: 'Merchant public key', example: 'merchant_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(201, description: 'Routing rule created')]
    #[Response(422, description: 'Validation error')]
    public function store(StoreRoutingRuleRequest $request, string $merchantKey): JsonResponse
    {
        $merchant = $this->routingRuleService->findMerchant($merchantKey);
        $dto = $request->toDto();

        $rule = $this->routingRuleService->create($merchant->id, [
            'business_profile_id' => $dto->business_profile_id,
            'type' => $dto->type,
            'name' => $dto->name,
            'rules' => $dto->rules,
            'active' => $dto->active,
            'priority' => $dto->priority,
        ]);

        return (new RoutingRuleResource($rule))
            ->withStatus(201)
            ->withHeader('Location', url("/api/v1/merchants/{$merchantKey}/routing-rules/{$rule->key}"))
            ->toResponse($request);
    }

    /**
     * List routing rules.
     *
     * Returns all routing rules for the specified merchant account.
     */
    #[PathParameter('merchantKey', description: 'Merchant public key', example: 'merchant_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(200, description: 'Routing rule list')]
    public function index(string $merchantKey, Request $request): JsonResponse
    {
        $merchant = $this->routingRuleService->findMerchant($merchantKey);
        $rules = $this->routingRuleService->listByMerchant($merchant->id);

        return RoutingRuleResource::jsonApiList($rules, $request);
    }

    /**
     * Get a routing rule.
     *
     * Retrieves the details of a specific routing rule.
     */
    #[PathParameter('merchantKey', description: 'Merchant public key', example: 'merchant_01jd5x7k3m9p2q4r6s8t0v')]
    #[PathParameter('ruleKey', description: 'Routing rule public key', example: 'rr_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(200, description: 'Routing rule details')]
    #[Response(404, description: 'Routing rule not found')]
    public function show(string $merchantKey, string $ruleKey, Request $request): JsonResponse
    {
        $merchant = $this->routingRuleService->findMerchant($merchantKey);
        $rule = $this->routingRuleService->findByKey($ruleKey, $merchant->id);

        return (new RoutingRuleResource($rule))->toResponse($request);
    }

    /**
     * Update a routing rule.
     *
     * Updates an existing routing rule's configuration. Only provided fields are updated.
     */
    #[PathParameter('merchantKey', description: 'Merchant public key', example: 'merchant_01jd5x7k3m9p2q4r6s8t0v')]
    #[PathParameter('ruleKey', description: 'Routing rule public key', example: 'rr_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(200, description: 'Routing rule updated')]
    #[Response(404, description: 'Routing rule not found')]
    public function update(UpdateRoutingRuleRequest $request, string $merchantKey, string $ruleKey): JsonResponse
    {
        $merchant = $this->routingRuleService->findMerchant($merchantKey);
        $dto = $request->toDto();

        $rule = $this->routingRuleService->update($ruleKey, $merchant->id, $dto->toUpdateArray());

        return (new RoutingRuleResource($rule))->toResponse($request);
    }

    /**
     * Delete a routing rule.
     *
     * Permanently removes a routing rule from the merchant account.
     */
    #[PathParameter('merchantKey', description: 'Merchant public key', example: 'merchant_01jd5x7k3m9p2q4r6s8t0v')]
    #[PathParameter('ruleKey', description: 'Routing rule public key', example: 'rr_01jd5x7k3m9p2q4r6s8t0v')]
    #[Response(204, description: 'Routing rule deleted')]
    #[Response(404, description: 'Routing rule not found')]
    public function destroy(string $merchantKey, string $ruleKey): JsonResponse
    {
        $merchant = $this->routingRuleService->findMerchant($merchantKey);
        $this->routingRuleService->delete($ruleKey, $merchant->id);

        return response()->json(null, 204);
    }
}
