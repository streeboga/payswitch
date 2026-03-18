<?php

declare(strict_types=1);

namespace App\Enums;

enum CacheKey: string
{
    case MerchantConnectors = 'merchant:%s:connectors';
    case MerchantAnalytics = 'merchant:%s:analytics:%s';
    case UserPreferences = 'user:%s:preferences';
    case ConnectorHealth = 'connector:%s:health:%s';

    public function with(mixed ...$params): string
    {
        return sprintf($this->value, ...$params);
    }

    public function getDefaultTtl(): CacheTtl
    {
        return match ($this) {
            self::MerchantConnectors => CacheTtl::FiveMinutes,
            self::MerchantAnalytics => CacheTtl::FifteenMinutes,
            self::UserPreferences => CacheTtl::OneHour,
            self::ConnectorHealth => CacheTtl::FiveMinutes,
        };
    }
}
