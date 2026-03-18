<?php

declare(strict_types=1);

namespace App\Builders;

use Illuminate\Database\Eloquent\Builder;

class PaymentIntentQueryBuilder extends Builder
{
    public function locked(): static
    {
        return $this->lockForUpdate();
    }

    public function forMerchant(int|string $merchantAccountId): static
    {
        return $this->where('merchant_account_id', $merchantAccountId);
    }

    public function byKey(string $key): static
    {
        return $this->where('key', $key);
    }
}
