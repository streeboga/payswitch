<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Services\WebhookReceiverService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;

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
     * 200 once the signature holds, 401 when it does not, 404 for an unknown merchant or connector.
     */
    #[PathParameter('merchantKey', description: 'Merchant public key')]
    #[PathParameter('mcaKey', description: 'Connector public key')]
    #[Response(200, description: 'Webhook processed')]
    public function handle(Request $request, string $merchantKey, string $mcaKey): JsonResponse|HttpResponse
    {
        $result = $this->webhookReceiverService->handle($request, $merchantKey, $mcaKey);

        // Some providers read the body and act on it — CloudPayments will not charge the
        // card unless the check notification is answered in its own dialect, T-Bank and
        // Robokassa resend until they see a bare `OK`. The driver supplies that body;
        // everyone else gets the plain acknowledgement.
        $ack = $result['ack'] ?? null;
        if (is_string($ack)) {
            // ponytail: the json-api middleware still stamps its Content-Type on this; the
            // providers read the body only.
            return response($ack, $result['code']);
        }

        return response()->json($ack ?? ['status' => $result['status']], $result['code']);
    }
}
