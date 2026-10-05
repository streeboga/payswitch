<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\WebhookEventService;
use Illuminate\Console\Command;

final class RetryWebhookOutboxCommand extends Command
{
    protected $signature = 'payswitch:webhooks:retry
        {--limit=100 : Сколько событий поставить в очередь, не больше 1000}
        {--older-than=400 : Только события, не тронутые столько минут (самый длинный интервал повтора — 360)}';

    protected $description = 'Снова поставить в очередь недоставленные события мерчантам, чьи задания доставки потеряны';

    public function handle(WebhookEventService $events): int
    {
        $count = $events->retryUndelivered((int) $this->option('limit'), (int) $this->option('older-than'));

        $this->info("Enqueued {$count} undelivered webhook events");

        return self::SUCCESS;
    }
}
