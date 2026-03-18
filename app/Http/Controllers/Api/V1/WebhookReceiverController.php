<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Services\WebhookReceiverService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group(name: 'Webhooks', description: 'Receive and process PSP webhook notifications', weight: 20)]
final class WebhookReceiverController
{
    public function __construct(
        private readonly WebhookReceiverService $webhookReceiverService,
    ) {}

    /**
     * Handle incoming PSP webhook.
     *
     * Receives and processes webhook notifications from payment service providers.
     * Always returns 200 to prevent unnecessary retries.
     */
    #[PathParameter('merchantKey', description: 'Merchant public key')]
    #[PathParameter('mcaKey', description: 'Connector public key')]
    #[Response(200, description: 'Webhook processed')]
    public function handle(Request $request, string $merchantKey, string $mcaKey): JsonResponse
    {
        $result = $this->webhookReceiverService->handle($request, $merchantKey, $mcaKey);

        return response()->json(['status' => $result['status']], $result['code']);
    }
}
