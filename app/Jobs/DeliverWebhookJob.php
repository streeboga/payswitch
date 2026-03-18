<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\WebhookEvent;
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

    public function handle(): void
    {
        $event = WebhookEvent::find($this->webhookEventId);
        if (! $event || $event->delivered) {
            return;
        }

        $profile = BusinessProfile::where('merchant_account_id', $event->merchant_account_id)->first();
        if (! $profile || ! $profile->webhook_url) {
            return;
        }

        $payload = json_encode([
            'event_id' => $event->key,
            'event_type' => $event->event_type,
            'content' => $event->content,
            'updated' => $event->updated_at?->toIso8601String(),
        ]);

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
                $event->update([
                    'delivered' => true,
                    'delivery_attempts' => $event->delivery_attempts + 1,
                ]);

                return;
            }

            $event->update([
                'delivery_attempts' => $event->delivery_attempts + 1,
                'last_error' => "HTTP {$response->status()}: {$response->body()}",
            ]);
        } catch (\Exception $e) {
            $event->update([
                'delivery_attempts' => $event->delivery_attempts + 1,
                'last_error' => $e->getMessage(),
            ]);
        }

        // If we've exhausted all retries, the job framework handles it
        if ($event->delivery_attempts >= config('payswitch.webhook.max_attempts', 16)) {
            $event->update(['last_error' => 'Max delivery attempts exceeded']);

            return;
        }

        // Rethrow to trigger retry with backoff
        throw new \RuntimeException("Webhook delivery failed for event {$event->key}");
    }
}
