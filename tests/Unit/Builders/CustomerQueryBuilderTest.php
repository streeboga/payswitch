<?php

declare(strict_types=1);

use App\Builders\CustomerQueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Models\Customer;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $org = Organization::create(['name' => 'Test Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'Merchant']);
    $this->otherMerchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'Other Merchant']);
});

test('make returns a new instance', function () {
    $builder = CustomerQueryBuilder::make();
    expect($builder)->toBeInstanceOf(CustomerQueryBuilder::class);
});

test('forMerchant filters by merchant_account_id', function () {
    Customer::create(['merchant_account_id' => $this->merchant->id, 'name' => 'Alice']);
    Customer::create(['merchant_account_id' => $this->otherMerchant->id, 'name' => 'Bob']);

    $results = CustomerQueryBuilder::make()
        ->forMerchant($this->merchant->id)
        ->getQuery()
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->name)->toBe('Alice');
});

test('whereKey filters by key', function () {
    $customer = Customer::create(['merchant_account_id' => $this->merchant->id, 'name' => 'Alice']);

    $result = CustomerQueryBuilder::make()
        ->whereKey($customer->key)
        ->first();

    expect($result)->not->toBeNull()
        ->and($result->id)->toBe($customer->id);
});

test('whereKey returns nothing for non-existent key', function () {
    Customer::create(['merchant_account_id' => $this->merchant->id, 'name' => 'Alice']);

    $result = CustomerQueryBuilder::make()
        ->whereKey('cus_nonexistent')
        ->first();

    expect($result)->toBeNull();
});

test('whereEmail filters by email', function () {
    Customer::create(['merchant_account_id' => $this->merchant->id, 'name' => 'Alice', 'email' => 'alice@example.com']);
    Customer::create(['merchant_account_id' => $this->merchant->id, 'name' => 'Bob', 'email' => 'bob@example.com']);

    $result = CustomerQueryBuilder::make()
        ->whereEmail('alice@example.com')
        ->first();

    expect($result)->not->toBeNull()
        ->and($result->name)->toBe('Alice');
});

test('search filters by name', function () {
    Customer::create(['merchant_account_id' => $this->merchant->id, 'name' => 'John Doe', 'email' => 'john@example.com']);
    Customer::create(['merchant_account_id' => $this->merchant->id, 'name' => 'Jane Smith', 'email' => 'jane@example.com']);

    $results = CustomerQueryBuilder::make()
        ->search('John')
        ->getQuery()
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->name)->toBe('John Doe');
});

test('search filters by email', function () {
    Customer::create(['merchant_account_id' => $this->merchant->id, 'name' => 'Alice', 'email' => 'alice@example.com']);
    Customer::create(['merchant_account_id' => $this->merchant->id, 'name' => 'Bob', 'email' => 'bob@other.com']);

    $results = CustomerQueryBuilder::make()
        ->search('alice@example')
        ->getQuery()
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->name)->toBe('Alice');
});

test('search filters by key partial match', function () {
    $customer = Customer::create(['merchant_account_id' => $this->merchant->id, 'name' => 'Alice', 'key' => 'cus_searchable123']);

    $results = CustomerQueryBuilder::make()
        ->search('searchable')
        ->getQuery()
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->id)->toBe($customer->id);
});

test('search with no match returns empty', function () {
    Customer::create(['merchant_account_id' => $this->merchant->id, 'name' => 'Alice', 'email' => 'alice@example.com']);

    $results = CustomerQueryBuilder::make()
        ->search('nonexistent_term')
        ->getQuery()
        ->get();

    expect($results)->toHaveCount(0);
});

test('sortBy orders by allowed column ascending', function () {
    Customer::create(['merchant_account_id' => $this->merchant->id, 'name' => 'Charlie']);
    Customer::create(['merchant_account_id' => $this->merchant->id, 'name' => 'Alice']);
    Customer::create(['merchant_account_id' => $this->merchant->id, 'name' => 'Bob']);

    $results = CustomerQueryBuilder::make()
        ->sortBy('name', 'asc')
        ->getQuery()
        ->get();

    expect($results->pluck('name')->toArray())->toBe(['Alice', 'Bob', 'Charlie']);
});

test('sortBy orders by allowed column descending', function () {
    Customer::create(['merchant_account_id' => $this->merchant->id, 'name' => 'Alice']);
    Customer::create(['merchant_account_id' => $this->merchant->id, 'name' => 'Charlie']);
    Customer::create(['merchant_account_id' => $this->merchant->id, 'name' => 'Bob']);

    $results = CustomerQueryBuilder::make()
        ->sortBy('name', 'desc')
        ->getQuery()
        ->get();

    expect($results->pluck('name')->toArray())->toBe(['Charlie', 'Bob', 'Alice']);
});

test('sortBy ignores disallowed column', function () {
    Customer::create(['merchant_account_id' => $this->merchant->id, 'name' => 'Alice']);
    Customer::create(['merchant_account_id' => $this->merchant->id, 'name' => 'Bob']);

    // Should not throw and should return results without ordering by 'phone'
    $results = CustomerQueryBuilder::make()
        ->sortBy('phone', 'asc')
        ->getQuery()
        ->get();

    expect($results)->toHaveCount(2);
});

test('exists returns true when matching records exist', function () {
    Customer::create(['merchant_account_id' => $this->merchant->id, 'name' => 'Alice']);

    $exists = CustomerQueryBuilder::make()
        ->forMerchant($this->merchant->id)
        ->exists();

    expect($exists)->toBeTrue();
});

test('exists returns false when no matching records', function () {
    $exists = CustomerQueryBuilder::make()
        ->forMerchant($this->merchant->id)
        ->exists();

    expect($exists)->toBeFalse();
});

test('firstOrFail returns first matching record', function () {
    $customer = Customer::create(['merchant_account_id' => $this->merchant->id, 'name' => 'Alice']);

    $result = CustomerQueryBuilder::make()
        ->forMerchant($this->merchant->id)
        ->firstOrFail();

    expect($result->id)->toBe($customer->id);
});

test('firstOrFail throws when no match', function () {
    CustomerQueryBuilder::make()
        ->forMerchant($this->merchant->id)
        ->firstOrFail();
})->throws(ModelNotFoundException::class);

test('first returns null when no match', function () {
    $result = CustomerQueryBuilder::make()
        ->forMerchant($this->merchant->id)
        ->first();

    expect($result)->toBeNull();
});

test('paginate returns paginated results', function () {
    for ($i = 0; $i < 5; $i++) {
        Customer::create(['merchant_account_id' => $this->merchant->id, 'name' => "Customer {$i}"]);
    }

    $paginated = CustomerQueryBuilder::make()
        ->forMerchant($this->merchant->id)
        ->paginate(2);

    expect($paginated->total())->toBe(5)
        ->and($paginated->perPage())->toBe(2)
        ->and($paginated->items())->toHaveCount(2);
});

test('paginate defaults to 20 per page', function () {
    Customer::create(['merchant_account_id' => $this->merchant->id, 'name' => 'Alice']);

    $paginated = CustomerQueryBuilder::make()
        ->forMerchant($this->merchant->id)
        ->paginate();

    expect($paginated->perPage())->toBe(20);
});

test('getQuery returns underlying builder', function () {
    $query = CustomerQueryBuilder::make()->getQuery();

    expect($query)->toBeInstanceOf(Builder::class);
});

test('methods can be chained together', function () {
    Customer::create(['merchant_account_id' => $this->merchant->id, 'name' => 'John Doe', 'email' => 'john@example.com']);
    Customer::create(['merchant_account_id' => $this->merchant->id, 'name' => 'Jane Smith', 'email' => 'jane@example.com']);
    Customer::create(['merchant_account_id' => $this->otherMerchant->id, 'name' => 'John Other', 'email' => 'john-other@example.com']);

    $results = CustomerQueryBuilder::make()
        ->forMerchant($this->merchant->id)
        ->search('John')
        ->sortBy('name', 'asc')
        ->getQuery()
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->name)->toBe('John Doe');
});
