<?php

declare(strict_types=1);

namespace App\Builders;

use Illuminate\Database\Eloquent\Builder;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;

final class MerchantConnectorQueryBuilder
{
    private Builder $query;

    public function __construct()
    {
        $this->query = MerchantConnectorAccount::query();
    }

    public static function make(): self
    {
        return new self;
    }

    public function forMerchant(int|string $merchantAccountId): self
    {
        $this->query->where('merchant_account_id', $merchantAccountId);

        return $this;
    }

    public function whereKey(string $key): self
    {
        $this->query->where('key', $key);

        return $this;
    }

    public function whereConnectorName(string $connectorName): self
    {
        $this->query->where('connector_name', $connectorName);

        return $this;
    }

    public function firstOrFail(): MerchantConnectorAccount
    {
        return $this->query->firstOrFail();
    }

    public function first(): ?MerchantConnectorAccount
    {
        return $this->query->first();
    }

    public function getQuery(): Builder
    {
        return $this->query;
    }
}
