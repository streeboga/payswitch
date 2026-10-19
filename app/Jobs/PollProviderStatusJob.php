<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\ProviderPollingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Раз в минуту: спросить провайдера о платежах «в обработке» и pending-возвратах,
 * которым пришёл срок (ProviderPollingService::SCHEDULE).
 *
 * Прогон ограничен: пачка и бюджет времени, остальное — следующим кругом. Бюджет плюс
 * один самый долгий запрос к провайдеру (30 с) меньше retry_after очереди (90 с).
 */
final class PollProviderStatusJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 85;

    /** Уникальность до конца работы, а не до постановки. */
    public int $uniqueFor = 90;

    private const int BATCH = 50;

    private const int BUDGET_SECONDS = 45;

    public function handle(ProviderPollingService $polling): void
    {
        $polling->run(self::BATCH, microtime(true) + self::BUDGET_SECONDS);
    }
}
