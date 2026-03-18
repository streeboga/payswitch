<?php

declare(strict_types=1);

namespace Streeboga\PaymentData\Support;

use Illuminate\Support\Str;

class IdGenerator
{
    public static function paymentId(): string
    {
        return config('payswitch.id_prefixes.payment').Str::ulid();
    }

    public static function customerId(): string
    {
        return config('payswitch.id_prefixes.customer').Str::ulid();
    }

    public static function refundId(): string
    {
        return config('payswitch.id_prefixes.refund').Str::ulid();
    }

    public static function profileId(): string
    {
        return config('payswitch.id_prefixes.profile').Str::ulid();
    }

    public static function merchantId(): string
    {
        return config('payswitch.id_prefixes.merchant').Str::ulid();
    }

    public static function mcaId(): string
    {
        return config('payswitch.id_prefixes.merchant_account').Str::ulid();
    }

    public static function orgId(): string
    {
        return config('payswitch.id_prefixes.organization').Str::ulid();
    }

    public static function eventId(): string
    {
        return config('payswitch.id_prefixes.event').Str::ulid();
    }

    public static function paymentMethodId(): string
    {
        return config('payswitch.id_prefixes.payment_method').Str::ulid();
    }

    public static function routingRuleId(): string
    {
        return config('payswitch.id_prefixes.routing_rule').Str::ulid();
    }

    public static function clientSecret(string $paymentId): string
    {
        return $paymentId.'_secret_'.Str::ulid();
    }

    public static function apiKey(string $environment): string
    {
        $prefix = $environment === 'production'
            ? config('payswitch.id_prefixes.product')
            : config('payswitch.id_prefixes.sender');

        return $prefix.Str::ulid();
    }

    public static function publishableKey(string $environment): string
    {
        $prefix = $environment === 'production'
            ? config('payswitch.id_prefixes.api_key').config('payswitch.id_prefixes.product')
            : config('payswitch.id_prefixes.api_key').config('payswitch.id_prefixes.sender');

        return $prefix.Str::ulid();
    }
}
