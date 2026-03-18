<?php

namespace App\Providers;

use App\Events\PaymentStatusChanged;
use App\Listeners\LogPaymentAudit;
use App\Listeners\SendWebhookNotification;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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

        Event::listen(PaymentStatusChanged::class, LogPaymentAudit::class);
        Event::listen(PaymentStatusChanged::class, SendWebhookNotification::class);
    }

    /**
     * Configure rate limiting for the API.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('payswitch-api', function ($request) {
            $type = $request->attributes->get('api_key_type', 'unknown');
            $limit = config("payswitch.rate_limit.{$type}", 60);
            $key = $request->attributes->get('merchant_id', $request->ip());

            return Limit::perMinute($limit)->by($type.':'.$key);
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

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
