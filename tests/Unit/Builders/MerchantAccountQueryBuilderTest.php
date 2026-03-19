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

test('search escapes percent wildcard in term', function () {
    $builder = MerchantAccountQueryBuilder::make()->search('100%');
    $query = $builder->getQuery();
    $bindings = $query->getBindings();

    // The % in the search term should be escaped to \%
    expect($bindings)->toContain('%100\%%');
});

test('search escapes underscore wildcard in term', function () {
    $builder = MerchantAccountQueryBuilder::make()->search('test_value');
    $query = $builder->getQuery();
    $bindings = $query->getBindings();

    // The _ in the search term should be escaped to \_
    expect($bindings)->toContain('%test\_value%');
});

test('search escapes both percent and underscore in term', function () {
    $builder = MerchantAccountQueryBuilder::make()->search('100%_test');
    $query = $builder->getQuery();
    $bindings = $query->getBindings();

    // Both % and _ should be escaped
    expect($bindings)->toContain('%100\%\_test%');
});

test('sortBy accepts created_at column', function () {
    $old = MerchantAccount::create(['org_id' => $this->org->id, 'name' => 'Old']);
    MerchantAccount::query()->where('id', $old->id)->update(['created_at' => now()->subHour()]);
    $new = MerchantAccount::create(['org_id' => $this->org->id, 'name' => 'New']);

    $results = MerchantAccountQueryBuilder::make()
        ->forOrganization($this->org->id)
        ->sortBy('created_at', 'desc')
        ->get();

    expect($results->first()->name)->toBe('New')
        ->and($results->last()->name)->toBe('Old');
});

test('sortBy accepts updated_at column', function () {
    // Create B first, then A, so insertion order is B, A
    $b = MerchantAccount::create(['org_id' => $this->org->id, 'name' => 'B']);
    $a = MerchantAccount::create(['org_id' => $this->org->id, 'name' => 'A']);
    // Make A have earlier updated_at than B
    MerchantAccount::query()->where('id', $a->id)->update(['updated_at' => now()->subHour()]);
    // Touch B to ensure it has later updated_at
    MerchantAccount::query()->where('id', $b->id)->update(['updated_at' => now()]);

    $results = MerchantAccountQueryBuilder::make()
        ->forOrganization($this->org->id)
        ->sortBy('updated_at', 'asc')
        ->get();

    // With sortBy working: A (earlier) comes first, B (later) comes second
    // Without sortBy (mutation): insertion order is B, A — so this would fail
    expect($results->first()->name)->toBe('A')
        ->and($results->last()->name)->toBe('B');
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
