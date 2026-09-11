<?php

declare(strict_types=1);

namespace App\Providers;

use App\Policies\AnalyticsPolicy;
use App\Policies\ApiKeyPolicy;
use App\Policies\BusinessProfilePolicy;
use App\Policies\ConnectorHealthPolicy;
use App\Policies\ConnectorPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\DisputePolicy;
use App\Policies\EventLogPolicy;
use App\Policies\MerchantAccountPolicy;
use App\Policies\OrganizationPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\RoutingRulePolicy;
use App\Policies\TestPaymentPolicy;
use App\Policies\UserRolePolicy;
use App\Policies\WebhookEventPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
        $this->registerMerchantPolicies();
        // ponytail: listeners in app/Listeners are auto-discovered by Laravel.
        // Registering them here too fires every listener twice.
    }

    /**
     * Configure rate limiting for the API.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('login', function ($request) {
            $email = strtolower((string) $request->string('email'));
            $perMinute = app()->environment('production') ? 5 : 30;

            return Limit::perMinute($perMinute)->by($email.'|'.$request->ip());
        });

        RateLimiter::for('two-factor', function ($request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id', $request->ip()));
        });

        RateLimiter::for('payswitch-api', function ($request) {
            $type = $request->attributes->get('api_key_type', 'unknown');
            $limit = config("payswitch.rate_limit.{$type}", 60);
            $key = $request->attributes->get('merchant_id', $request->ip());

            return Limit::perMinute($limit)->by($type.':'.$key);
        });
    }

    /**
     * Register merchant-scoped policy gates.
     */
    private function registerMerchantPolicies(): void
    {
        $map = [
            'organization' => OrganizationPolicy::class,
            'merchant-account' => MerchantAccountPolicy::class,
            'payment' => PaymentPolicy::class,
            'customer' => CustomerPolicy::class,
            'connector' => ConnectorPolicy::class,
            'routing-rule' => RoutingRulePolicy::class,
            'dispute' => DisputePolicy::class,
            'webhook-event' => WebhookEventPolicy::class,
            'api-key' => ApiKeyPolicy::class,
            'business-profile' => BusinessProfilePolicy::class,
            'analytics' => AnalyticsPolicy::class,
            'user-role' => UserRolePolicy::class,
            'event-log' => EventLogPolicy::class,
            'connector-health' => ConnectorHealthPolicy::class,
            'test-payment' => TestPaymentPolicy::class,
        ];

        foreach ($map as $resource => $policyClass) {
            foreach (get_class_methods($policyClass) as $method) {
                if (str_starts_with($method, '__')) {
                    continue;
                }

                Gate::define("{$resource}.{$method}", [$policyClass, $method]);
            }
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        Model::preventLazyLoading(! app()->isProduction());

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
