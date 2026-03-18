<?php

declare(strict_types=1);

namespace App\Providers;

use App\Repositories\Contracts\AnalyticsRepositoryInterface;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Repositories\Contracts\MerchantRepositoryInterface;
use App\Repositories\Contracts\PaymentIntentRepositoryInterface;
use App\Repositories\Contracts\PaymentMethodRepositoryInterface;
use App\Repositories\Contracts\RefundRepositoryInterface;
use App\Repositories\Contracts\RoutingRuleRepositoryInterface;
use App\Repositories\Contracts\WebhookEventRepositoryInterface;
use App\Repositories\Eloquent\AnalyticsRepository;
use App\Repositories\Eloquent\CustomerRepository;
use App\Repositories\Eloquent\MerchantRepository;
use App\Repositories\Eloquent\PaymentIntentRepository;
use App\Repositories\Eloquent\PaymentMethodRepository;
use App\Repositories\Eloquent\RefundRepository;
use App\Repositories\Eloquent\RoutingRuleRepository;
use App\Repositories\Eloquent\WebhookEventRepository;
use Illuminate\Support\ServiceProvider;

final class RepositoryServiceProvider extends ServiceProvider
{
    public array $bindings = [
        AnalyticsRepositoryInterface::class => AnalyticsRepository::class,
        PaymentIntentRepositoryInterface::class => PaymentIntentRepository::class,
        CustomerRepositoryInterface::class => CustomerRepository::class,
        MerchantRepositoryInterface::class => MerchantRepository::class,
        RefundRepositoryInterface::class => RefundRepository::class,
        WebhookEventRepositoryInterface::class => WebhookEventRepository::class,
        RoutingRuleRepositoryInterface::class => RoutingRuleRepository::class,
        PaymentMethodRepositoryInterface::class => PaymentMethodRepository::class,
    ];
}
