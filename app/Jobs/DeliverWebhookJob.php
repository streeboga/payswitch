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
use Streeboga\PaymentData\Models\WebhookEvent;
use Streeboga\PaymentData\Support\WebhookSigner;

final class DeliverWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    /** @var array<int, int> */
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

        // Адрес — профиля платежа. Профиль мерчанта — только для событий без
        // профиля (старые строки): у мерчанта их может быть несколько.
        $profile = ($event->business_profile_id ? $merchantRepository->findProfileById($event->business_profile_id) : null)
            ?? $merchantRepository->findProfileByMerchant($event->merchant_account_id);

        if (! $profile || ! $profile->webhook_url) {
            // Не выбрасывать: адрес могут прописать позже, событие — деньги получателя.
            Log::warning("No webhook URL configured for event {$event->key}", ['merchant_account_id' => $event->merchant_account_id]);
            $this->retryOrFail($webhookRepository, $event, 'No webhook URL configured');

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
            // Время факта, а не последней попытки: updated_at сдвигается на
            // каждой неудаче, и ретрай старого события выглядел бы новее
            // следующего. `updated` оставлен ради совместимости с приёмниками.
            'created' => $event->created_at->toIso8601String(),
            'updated' => $event->created_at->toIso8601String(),
        ], JSON_THROW_ON_ERROR);

        $signature = WebhookSigner::sign($payload, $profile->payment_response_hash_key);

        try {
            $response = Http::timeout(config('payswitch.webhook.timeout', 30))
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'x-webhook-signature-512' => $signature,
                    'x-webhook-event-id' => $event->key,
                ])
                ->withBody($payload, 'application/json')
                ->post($profile->webhook_url);

            if ($response->successful()) {
                $webhookRepository->markDelivered($event, $event->delivery_attempts + 1);

                return;
            }

            $error = Str::limit("HTTP {$response->status()}: {$response->body()}", 1000);
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }

        $this->retryOrFail($webhookRepository, $event, $error);
    }

    /**
     * Неудачная попытка: записать ошибку и либо уйти на повтор по расписанию,
     * либо, когда попытки кончились, провалить job — тогда вызовется failed()
     * и запись ляжет в failed_jobs.
     */
    private function retryOrFail(WebhookEventRepositoryInterface $webhookRepository, WebhookEvent $event, string $error): void
    {
        $attempts = $event->delivery_attempts + 1;
        $webhookRepository->markFailed($event, $attempts, $error);

        $exception = new \RuntimeException("Webhook delivery failed for event {$event->key} after {$attempts} attempts");

        if ($attempts >= config('payswitch.webhook.max_attempts', 16)) {
            $this->fail($exception);

            return;
        }

        throw $exception;
    }

    public function failed(\Throwable $e): void
    {
        $webhookRepository = app(WebhookEventRepositoryInterface::class);
        $event = $webhookRepository->findById($this->webhookEventId);

        // Настоящую причину не затирать — дописать к ней.
        if ($event) {
            $webhookRepository->markFailed(
                $event,
                $event->delivery_attempts,
                ($event->last_error ? $event->last_error.' | ' : '').'permanently failed: '.$e->getMessage(),
            );
        }

        Log::error('Webhook delivery permanently failed for event '.($event->key ?? $this->webhookEventId), [
            'webhook_event_id' => $this->webhookEventId,
            'merchant_account_id' => $event?->merchant_account_id,
            'payment_id' => $event?->content['payment_id'] ?? null,
            'attempts' => $event?->delivery_attempts,
            'last_error' => $event?->last_error,
            'error' => $e->getMessage(),
        ]);
    }
}
