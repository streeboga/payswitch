<?php

declare(strict_types=1);

namespace App\Providers;

use App\Repositories\Contracts\AnalyticsRepositoryInterface;
use App\Repositories\Contracts\AuditLogRepositoryInterface;
use App\Repositories\Contracts\ConnectorHealthRepositoryInterface;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Repositories\Contracts\DisputeRepositoryInterface;
use App\Repositories\Contracts\EventLogRepositoryInterface;
use App\Repositories\Contracts\MerchantRepositoryInterface;
use App\Repositories\Contracts\NotificationRepositoryInterface;
use App\Repositories\Contracts\PaymentIntentRepositoryInterface;
use App\Repositories\Contracts\PaymentMethodRepositoryInterface;
use App\Repositories\Contracts\RefundRepositoryInterface;
use App\Repositories\Contracts\RoutingRuleRepositoryInterface;
use App\Repositories\Contracts\SavedFilterRepositoryInterface;
use App\Repositories\Contracts\UserPreferenceRepositoryInterface;
use App\Repositories\Contracts\UserRoleRepositoryInterface;
use App\Repositories\Contracts\WebhookEventRepositoryInterface;
use App\Repositories\Eloquent\AnalyticsRepository;
use App\Repositories\Eloquent\AuditLogRepository;
use App\Repositories\Eloquent\ConnectorHealthRepository;
use App\Repositories\Eloquent\CustomerRepository;
use App\Repositories\Eloquent\DisputeRepository;
use App\Repositories\Eloquent\EventLogRepository;
use App\Repositories\Eloquent\MerchantRepository;
use App\Repositories\Eloquent\NotificationRepository;
use App\Repositories\Eloquent\PaymentIntentRepository;
use App\Repositories\Eloquent\PaymentMethodRepository;
use App\Repositories\Eloquent\RefundRepository;
use App\Repositories\Eloquent\RoutingRuleRepository;
use App\Repositories\Eloquent\SavedFilterRepository;
use App\Repositories\Eloquent\UserPreferenceRepository;
use App\Repositories\Eloquent\UserRoleRepository;
use App\Repositories\Eloquent\WebhookEventRepository;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

final class RepositoryServiceProvider extends ServiceProvider
{
    /** @var array<string, string> */
    public array $bindings = [
        AnalyticsRepositoryInterface::class => AnalyticsRepository::class,
        AuditLogRepositoryInterface::class => AuditLogRepository::class,
        ConnectorHealthRepositoryInterface::class => ConnectorHealthRepository::class,
        CustomerRepositoryInterface::class => CustomerRepository::class,
        DisputeRepositoryInterface::class => DisputeRepository::class,
        EventLogRepositoryInterface::class => EventLogRepository::class,
        MerchantRepositoryInterface::class => MerchantRepository::class,
        NotificationRepositoryInterface::class => NotificationRepository::class,
        PaymentIntentRepositoryInterface::class => PaymentIntentRepository::class,
        PaymentMethodRepositoryInterface::class => PaymentMethodRepository::class,
        RefundRepositoryInterface::class => RefundRepository::class,
        RoutingRuleRepositoryInterface::class => RoutingRuleRepository::class,
        SavedFilterRepositoryInterface::class => SavedFilterRepository::class,
        UserPreferenceRepositoryInterface::class => UserPreferenceRepository::class,
        UserRoleRepositoryInterface::class => UserRoleRepository::class,
        WebhookEventRepositoryInterface::class => WebhookEventRepository::class,
    ];

    /**
     * Номер страницы приходит как page[number] (JSON:API), а Laravel по умолчанию
     * читает скалярный ?page=. Без этого вторая страница молча отдаёт первую —
     * во всех списках сразу, поэтому правится один раз здесь.
     */
    public function boot(): void
    {
        Paginator::currentPageResolver(function (): int {
            $page = request()->input('page');
            $number = is_array($page) ? ($page['number'] ?? 1) : $page;

            return max(1, (int) $number);
        });
    }
}
