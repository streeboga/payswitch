<?php

declare(strict_types=1);

use App\Builders\MerchantAccountQueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->org = Organization::create(['name' => 'Org A']);
    $this->otherOrg = Organization::create(['name' => 'Org B']);
});

test('make returns a new instance', function () {
    expect(MerchantAccountQueryBuilder::make())->toBeInstanceOf(MerchantAccountQueryBuilder::class);
});

test('forOrganization filters by org_id', function () {
    MerchantAccount::create(['org_id' => $this->org->id, 'name' => 'Merchant A']);
    MerchantAccount::create(['org_id' => $this->otherOrg->id, 'name' => 'Merchant B']);

    $results = MerchantAccountQueryBuilder::make()
        ->forOrganization($this->org->id)
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->name)->toBe('Merchant A');
});

test('whereKey filters by key', function () {
    $merchant = MerchantAccount::create(['org_id' => $this->org->id, 'name' => 'Merchant A']);

    $result = MerchantAccountQueryBuilder::make()
        ->whereKey($merchant->key)
        ->first();

    expect($result)->not->toBeNull()
        ->and($result->id)->toBe($merchant->id);
});

test('whereKey returns nothing for non-existent key', function () {
    MerchantAccount::create(['org_id' => $this->org->id, 'name' => 'Merchant A']);

    $result = MerchantAccountQueryBuilder::make()
        ->whereKey('mer_nonexistent')
        ->first();

    expect($result)->toBeNull();
});

test('search filters by name', function () {
    MerchantAccount::create(['org_id' => $this->org->id, 'name' => 'Alpha Store']);
    MerchantAccount::create(['org_id' => $this->org->id, 'name' => 'Beta Shop']);

    $results = MerchantAccountQueryBuilder::make()
        ->search('Alpha')
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->name)->toBe('Alpha Store');
});

test('search matches partial name', function () {
    MerchantAccount::create(['org_id' => $this->org->id, 'name' => 'Alpha Store Premium']);
    MerchantAccount::create(['org_id' => $this->org->id, 'name' => 'Beta Shop']);

    $results = MerchantAccountQueryBuilder::make()
        ->search('Store')
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->name)->toBe('Alpha Store Premium');
});

test('search with no match returns empty', function () {
    MerchantAccount::create(['org_id' => $this->org->id, 'name' => 'Alpha Store']);

    $results = MerchantAccountQueryBuilder::make()
        ->search('nonexistent_term_xyz')
        ->get();

    expect($results)->toHaveCount(0);
});

test('withOrganization eager loads organization', function () {
    MerchantAccount::create(['org_id' => $this->org->id, 'name' => 'Merchant']);

    $result = MerchantAccountQueryBuilder::make()
        ->withOrganization()
        ->first();

    expect($result->relationLoaded('organization'))->toBeTrue()
        ->and($result->organization->name)->toBe('Org A');
});

test('withCounts loads relationship counts', function () {
    MerchantAccount::create(['org_id' => $this->org->id, 'name' => 'Merchant']);

    $result = MerchantAccountQueryBuilder::make()
        ->withCounts()
        ->first();

    expect($result->business_profiles_count)->toBe(0)
        ->and($result->connector_accounts_count)->toBe(0);
});

test('sortBy orders by allowed column ascending', function () {
    MerchantAccount::create(['org_id' => $this->org->id, 'name' => 'Charlie']);
    MerchantAccount::create(['org_id' => $this->org->id, 'name' => 'Alice']);
    MerchantAccount::create(['org_id' => $this->org->id, 'name' => 'Bob']);

    $results = MerchantAccountQueryBuilder::make()
        ->forOrganization($this->org->id)
        ->sortBy('name', 'asc')
        ->get();

    expect($results->pluck('name')->toArray())->toBe(['Alice', 'Bob', 'Charlie']);
});

test('sortBy orders by allowed column descending', function () {
    MerchantAccount::create(['org_id' => $this->org->id, 'name' => 'Alice']);
    MerchantAccount::create(['org_id' => $this->org->id, 'name' => 'Charlie']);
    MerchantAccount::create(['org_id' => $this->org->id, 'name' => 'Bob']);

    $results = MerchantAccountQueryBuilder::make()
        ->forOrganization($this->org->id)
        ->sortBy('name', 'desc')
        ->get();

    expect($results->pluck('name')->toArray())->toBe(['Charlie', 'Bob', 'Alice']);
});

test('sortBy ignores disallowed column', function () {
    MerchantAccount::create(['org_id' => $this->org->id, 'name' => 'Alice']);
    MerchantAccount::create(['org_id' => $this->org->id, 'name' => 'Bob']);

    $results = MerchantAccountQueryBuilder::make()
        ->sortBy('publishable_key', 'asc')
        ->get();

    expect($results)->toHaveCount(2);
});

test('latest orders by created_at descending', function () {
    $first = MerchantAccount::create(['org_id' => $this->org->id, 'name' => 'First']);
    // Ensure different timestamps
    MerchantAccount::query()->where('id', $first->id)->update(['created_at' => now()->subMinute()]);

    $second = MerchantAccount::create(['org_id' => $this->org->id, 'name' => 'Second']);

    $results = MerchantAccountQueryBuilder::make()
        ->forOrganization($this->org->id)
        ->latest()
        ->get();

    expect($results->first()->name)->toBe('Second');
});

test('firstOrFail returns first matching record', function () {
    $merchant = MerchantAccount::create(['org_id' => $this->org->id, 'name' => 'Test']);

    $result = MerchantAccountQueryBuilder::make()
        ->whereKey($merchant->key)
        ->firstOrFail();

    expect($result->id)->toBe($merchant->id);
});

test('firstOrFail throws when no match', function () {
    MerchantAccountQueryBuilder::make()
        ->whereKey('mer_nonexistent')
        ->firstOrFail();
})->throws(ModelNotFoundException::class);

test('first returns null when no match', function () {
    $result = MerchantAccountQueryBuilder::make()
        ->whereKey('mer_nonexistent')
        ->first();

    expect($result)->toBeNull();
});

test('get returns collection of results', function () {
    MerchantAccount::create(['org_id' => $this->org->id, 'name' => 'A']);
    MerchantAccount::create(['org_id' => $this->org->id, 'name' => 'B']);

    $results = MerchantAccountQueryBuilder::make()
        ->forOrganization($this->org->id)
        ->get();

    expect($results)->toHaveCount(2)
        ->and($results)->toBeInstanceOf(Collection::class);
});

test('paginate returns paginated results', function () {
    for ($i = 0; $i < 5; $i++) {
        MerchantAccount::create(['org_id' => $this->org->id, 'name' => "Merchant {$i}"]);
    }

    $paginated = MerchantAccountQueryBuilder::make()
        ->forOrganization($this->org->id)
        ->paginate(2);

    expect($paginated->total())->toBe(5)
        ->and($paginated->perPage())->toBe(2)
        ->and($paginated->items())->toHaveCount(2);
});

test('paginate defaults to 20 per page', function () {
    MerchantAccount::create(['org_id' => $this->org->id, 'name' => 'Test']);

    $paginated = MerchantAccountQueryBuilder::make()
        ->paginate();

    expect($paginated->perPage())->toBe(20);
});

test('getQuery returns underlying builder', function () {
    $query = MerchantAccountQueryBuilder::make()->getQuery();
    expect($query)->toBeInstanceOf(Builder::class);
});

test('methods can be chained together', function () {
    MerchantAccount::create(['org_id' => $this->org->id, 'name' => 'Alpha Store']);
    MerchantAccount::create(['org_id' => $this->org->id, 'name' => 'Beta Shop']);
    MerchantAccount::create(['org_id' => $this->otherOrg->id, 'name' => 'Alpha Other']);

    $results = MerchantAccountQueryBuilder::make()
        ->forOrganization($this->org->id)
        ->search('Alpha')
        ->sortBy('name', 'asc')
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->name)->toBe('Alpha Store');
});
