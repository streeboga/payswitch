<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

        if (! $this->isUrlSafe($profile->webhook_url)) {
            Log::warning("Blocked webhook delivery to unsafe URL for event {$this->webhookEventId}");
            $event->update(['last_error' => 'Webhook URL blocked: internal/private address']);

            return;
        }

        if (! $profile->payment_response_hash_key) {
            Log::warning("No webhook signing key for merchant {$event->merchant_account_id}");
            $event->update(['last_error' => 'No signing key configured']);

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

    public function failed(\Throwable $e): void
    {
        $event = WebhookEvent::find($this->webhookEventId);
        if ($event) {
            $event->update(['last_error' => 'Permanently failed: '.$e->getMessage()]);
        }
        Log::error("Webhook delivery permanently failed for event {$this->webhookEventId}", [
            'error' => $e->getMessage(),
        ]);
    }

    private function isUrlSafe(string $url): bool
    {
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';

        // Block private/internal IPs
        $ip = gethostbyname($host);
        if ($ip === $host) {
            return true; // hostname didn't resolve — let HTTP client handle it
        }

        $blockedRanges = ['10.', '172.16.', '172.17.', '172.18.', '172.19.', '172.2', '172.30.', '172.31.', '192.168.', '127.', '169.254.', '0.'];
        foreach ($blockedRanges as $range) {
            if (str_starts_with($ip, $range)) {
                return false;
            }
        }

        return true;
    }
}
