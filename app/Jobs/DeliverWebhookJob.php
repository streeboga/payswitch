<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Repositories\Contracts\MerchantRepositoryInterface;
use App\Repositories\Contracts\WebhookEventRepositoryInterface;
use App\Support\UrlSafetyValidator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Streeboga\PaymentData\Support\WebhookSigner;

final class DeliverWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public array $backoff;

    public function __construct(
        public readonly int $webhookEventId,
    ) {
        $retrySchedule = config('payswitch.webhook.retry_schedule', [1, 5, 5, 10, 10, 10, 10, 10, 60, 60, 60, 60, 60, 360, 360, 360]);
        $this->tries = count($retrySchedule) + 1;
        $this->backoff = array_map(fn ($m) => $m * 60, $retrySchedule); // minutes to seconds
    }

    public function handle(
        WebhookEventRepositoryInterface $webhookRepository,
        MerchantRepositoryInterface $merchantRepository,
    ): void {
        $event = $webhookRepository->findById($this->webhookEventId);
        if (! $event || $event->delivered) {
            return;
        }

        $profile = $merchantRepository->findProfileByMerchant($event->merchant_account_id);
        if (! $profile || ! $profile->webhook_url) {
            return;
        }

        if (! UrlSafetyValidator::isSafe($profile->webhook_url)) {
            Log::warning("Blocked webhook delivery to unsafe URL for event {$this->webhookEventId}");
            $webhookRepository->markFailed($event, $event->delivery_attempts, 'Webhook URL blocked: internal/private address');

            return;
        }

        if (! $profile->payment_response_hash_key) {
            Log::warning("No webhook signing key for merchant {$event->merchant_account_id}");
            $webhookRepository->markFailed($event, $event->delivery_attempts, 'No signing key configured');

            return;
        }

        $payload = json_encode([
            'event_id' => $event->key,
            'event_type' => $event->event_type,
            'content' => $event->content,
            'updated' => $event->updated_at?->toIso8601String(),
        ], JSON_THROW_ON_ERROR);

        $signature = WebhookSigner::sign($payload, $profile->payment_response_hash_key);

        try {
            $response = Http::timeout(config('payswitch.webhook.timeout', 30))
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'x-webhook-signature-512' => $signature,
                ])
                ->withBody($payload, 'application/json')
                ->post($profile->webhook_url);

            if ($response->successful()) {
                $webhookRepository->markDelivered($event, $event->delivery_attempts + 1);

                return;
            }

            $error = Str::limit("HTTP {$response->status()}: {$response->body()}", 1000);
            $webhookRepository->markFailed($event, $event->delivery_attempts + 1, $error);
        } catch (\Exception $e) {
            $webhookRepository->markFailed($event, $event->delivery_attempts + 1, $e->getMessage());
        }

        // If we've exhausted all retries, the job framework handles it
        if ($event->delivery_attempts >= config('payswitch.webhook.max_attempts', 16)) {
            $webhookRepository->markFailed($event, $event->delivery_attempts, 'Max delivery attempts exceeded');

            return;
        }

        // Rethrow to trigger retry with backoff
        throw new \RuntimeException("Webhook delivery failed for event {$event->key}");
    }

    public function failed(\Throwable $e): void
    {
        $webhookRepository = app(WebhookEventRepositoryInterface::class);
        $event = $webhookRepository->findById($this->webhookEventId);
        if ($event) {
            $webhookRepository->markFailed($event, $event->delivery_attempts, 'Permanently failed: '.$e->getMessage());
        }
        Log::error("Webhook delivery permanently failed for event {$this->webhookEventId}", [
            'error' => $e->getMessage(),
        ]);
    }
}
