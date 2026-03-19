<?php

declare(strict_types=1);

use App\Builders\BusinessProfileQueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Models\BusinessProfile;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

covers(BusinessProfileQueryBuilder::class);

beforeEach(function () {
    $org = Organization::create(['name' => 'Test Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'Merchant']);
    $this->otherMerchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'Other']);
});

test('make returns a new instance', function () {
    expect(BusinessProfileQueryBuilder::make())->toBeInstanceOf(BusinessProfileQueryBuilder::class);
});

test('forMerchant filters by merchant_account_id', function () {
    BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);
    BusinessProfile::create(['merchant_account_id' => $this->otherMerchant->id]);

    $results = BusinessProfileQueryBuilder::make()
        ->forMerchant($this->merchant->id)
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->merchant_account_id)->toBe($this->merchant->id);
});

test('whereKey filters by key', function () {
    $profile = BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);

    $result = BusinessProfileQueryBuilder::make()
        ->whereKey($profile->key)
        ->first();

    expect($result)->not->toBeNull()
        ->and($result->id)->toBe($profile->id);
});

test('whereKey returns nothing for non-existent key', function () {
    BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);

    $result = BusinessProfileQueryBuilder::make()
        ->whereKey('pro_nonexistent')
        ->first();

    expect($result)->toBeNull();
});

test('withMerchantAccount eager loads merchantAccount', function () {
    BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);

    $result = BusinessProfileQueryBuilder::make()
        ->withMerchantAccount()
        ->first();

    expect($result->relationLoaded('merchantAccount'))->toBeTrue()
        ->and($result->merchantAccount->name)->toBe('Merchant');
});

test('withCounts loads relationship counts', function () {
    BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);

    $result = BusinessProfileQueryBuilder::make()
        ->withCounts()
        ->first();

    expect($result->connector_accounts_count)->toBe(0)
        ->and($result->routing_rules_count)->toBe(0);
});

test('sortBy orders by created_at ascending', function () {
    $first = BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);
    BusinessProfile::query()->where('id', $first->id)->update(['created_at' => now()->subMinutes(2)]);

    $second = BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);
    BusinessProfile::query()->where('id', $second->id)->update(['created_at' => now()->subMinute()]);

    $results = BusinessProfileQueryBuilder::make()
        ->forMerchant($this->merchant->id)
        ->sortBy('created_at', 'asc')
        ->get();

    expect($results->first()->id)->toBe($first->id)
        ->and($results->last()->id)->toBe($second->id);
});

test('sortBy orders by created_at descending', function () {
    $first = BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);
    BusinessProfile::query()->where('id', $first->id)->update(['created_at' => now()->subMinutes(2)]);

    $second = BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);

    $results = BusinessProfileQueryBuilder::make()
        ->forMerchant($this->merchant->id)
        ->sortBy('created_at', 'desc')
        ->get();

    expect($results->first()->id)->toBe($second->id);
});

test('sortBy orders by updated_at ascending', function () {
    // Insert first record with LATER updated_at so natural order differs from sorted order
    $first = BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);
    BusinessProfile::query()->where('id', $first->id)->update(['updated_at' => now()->addMinute()]);

    $second = BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);
    BusinessProfile::query()->where('id', $second->id)->update(['updated_at' => now()->subMinute()]);

    // Ascending by updated_at: $second (earlier) should come before $first (later)
    $results = BusinessProfileQueryBuilder::make()
        ->forMerchant($this->merchant->id)
        ->sortBy('updated_at', 'asc')
        ->get();

    expect($results->first()->id)->toBe($second->id)
        ->and($results->last()->id)->toBe($first->id);
});

test('sortBy ignores disallowed column', function () {
    BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);
    BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);

    // 'webhook_url' is not in the allowed list
    $results = BusinessProfileQueryBuilder::make()
        ->sortBy('webhook_url', 'asc')
        ->get();

    expect($results)->toHaveCount(2);
});

test('latest orders by created_at descending', function () {
    $first = BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);
    BusinessProfile::query()->where('id', $first->id)->update(['created_at' => now()->subMinute()]);

    $second = BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);

    $results = BusinessProfileQueryBuilder::make()
        ->forMerchant($this->merchant->id)
        ->latest()
        ->get();

    expect($results->first()->id)->toBe($second->id);
});

test('firstOrFail returns first matching record', function () {
    $profile = BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);

    $result = BusinessProfileQueryBuilder::make()
        ->whereKey($profile->key)
        ->firstOrFail();

    expect($result->id)->toBe($profile->id);
});

test('firstOrFail throws when no match', function () {
    BusinessProfileQueryBuilder::make()
        ->whereKey('pro_nonexistent')
        ->firstOrFail();
})->throws(ModelNotFoundException::class);

test('first returns null when no match', function () {
    $result = BusinessProfileQueryBuilder::make()
        ->whereKey('pro_nonexistent')
        ->first();

    expect($result)->toBeNull();
});

test('get returns collection of results', function () {
    BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);
    BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);

    $results = BusinessProfileQueryBuilder::make()
        ->forMerchant($this->merchant->id)
        ->get();

    expect($results)->toHaveCount(2)
        ->and($results)->toBeInstanceOf(Collection::class);
});

test('paginate returns paginated results', function () {
    for ($i = 0; $i < 5; $i++) {
        BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);
    }

    $paginated = BusinessProfileQueryBuilder::make()
        ->forMerchant($this->merchant->id)
        ->paginate(2);

    expect($paginated->total())->toBe(5)
        ->and($paginated->perPage())->toBe(2)
        ->and($paginated->items())->toHaveCount(2);
});

test('paginate defaults to 20 per page', function () {
    BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);

    $paginated = BusinessProfileQueryBuilder::make()->paginate();

    expect($paginated->perPage())->toBe(20);
});

test('getQuery returns underlying builder', function () {
    $query = BusinessProfileQueryBuilder::make()->getQuery();
    expect($query)->toBeInstanceOf(Builder::class);
});

test('methods can be chained together', function () {
    $profile = BusinessProfile::create(['merchant_account_id' => $this->merchant->id]);
    BusinessProfile::create(['merchant_account_id' => $this->otherMerchant->id]);

    $results = BusinessProfileQueryBuilder::make()
        ->forMerchant($this->merchant->id)
        ->whereKey($profile->key)
        ->latest()
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->id)->toBe($profile->id);
});
