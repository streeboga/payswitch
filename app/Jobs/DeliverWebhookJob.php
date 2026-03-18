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
use Illuminate\Support\Str;
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

            $error = Str::limit("HTTP {$response->status()}: {$response->body()}", 1000);
            $event->update([
                'delivery_attempts' => $event->delivery_attempts + 1,
                'last_error' => $error,
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

        // Only allow http/https
        $scheme = $parsed['scheme'] ?? '';
        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        // Block URLs with userinfo
        if (isset($parsed['user']) || isset($parsed['pass'])) {
            return false;
        }

        $host = $parsed['host'] ?? '';
        if (empty($host) || $host === 'localhost') {
            return false;
        }

        // Check if host is an IP address
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        // Resolve hostname
        $ip = gethostbyname($host);
        if ($ip !== $host && filter_var($ip, FILTER_VALIDATE_IP)) {
            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return true;
    }
}
