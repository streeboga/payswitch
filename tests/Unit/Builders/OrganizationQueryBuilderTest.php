<?php

declare(strict_types=1);

use App\Builders\OrganizationQueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('make returns a new instance', function () {
    expect(OrganizationQueryBuilder::make())->toBeInstanceOf(OrganizationQueryBuilder::class);
});

test('whereKey filters by key', function () {
    $org = Organization::create(['name' => 'Target Org']);
    Organization::create(['name' => 'Other Org']);

    $result = OrganizationQueryBuilder::make()
        ->whereKey($org->key)
        ->first();

    expect($result)->not->toBeNull()
        ->and($result->id)->toBe($org->id);
});

test('whereKey returns nothing for non-existent key', function () {
    Organization::create(['name' => 'Org']);

    $result = OrganizationQueryBuilder::make()
        ->whereKey('org_nonexistent')
        ->first();

    expect($result)->toBeNull();
});

test('search filters by name', function () {
    Organization::create(['name' => 'Acme Corporation']);
    Organization::create(['name' => 'Beta Industries']);

    $results = OrganizationQueryBuilder::make()
        ->search('Acme')
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->name)->toBe('Acme Corporation');
});

test('search with no match returns empty', function () {
    Organization::create(['name' => 'Acme Corporation']);

    $results = OrganizationQueryBuilder::make()
        ->search('nonexistent_term_xyz')
        ->get();

    expect($results)->toHaveCount(0);
});

test('search is case insensitive partial match', function () {
    Organization::create(['name' => 'Acme Corporation']);
    Organization::create(['name' => 'Beta Industries']);

    $results = OrganizationQueryBuilder::make()
        ->search('acme')
        ->get();

    // SQLite LIKE is case-insensitive for ASCII by default
    expect($results)->toHaveCount(1)
        ->and($results->first()->name)->toBe('Acme Corporation');
});

test('withMerchantCount loads merchant accounts count', function () {
    $org = Organization::create(['name' => 'Org']);
    MerchantAccount::create(['org_id' => $org->id, 'name' => 'M1']);
    MerchantAccount::create(['org_id' => $org->id, 'name' => 'M2']);

    $result = OrganizationQueryBuilder::make()
        ->whereKey($org->key)
        ->withMerchantCount()
        ->first();

    expect($result->merchant_accounts_count)->toBe(2);
});

test('withMerchantCount returns zero when no merchants', function () {
    $org = Organization::create(['name' => 'Empty Org']);

    $result = OrganizationQueryBuilder::make()
        ->whereKey($org->key)
        ->withMerchantCount()
        ->first();

    expect($result->merchant_accounts_count)->toBe(0);
});

test('sortBy orders by allowed column ascending', function () {
    Organization::create(['name' => 'Charlie']);
    Organization::create(['name' => 'Alice']);
    Organization::create(['name' => 'Bob']);

    $results = OrganizationQueryBuilder::make()
        ->sortBy('name', 'asc')
        ->get();

    expect($results->pluck('name')->toArray())->toBe(['Alice', 'Bob', 'Charlie']);
});

test('sortBy orders by allowed column descending', function () {
    Organization::create(['name' => 'Alice']);
    Organization::create(['name' => 'Charlie']);
    Organization::create(['name' => 'Bob']);

    $results = OrganizationQueryBuilder::make()
        ->sortBy('name', 'desc')
        ->get();

    expect($results->pluck('name')->toArray())->toBe(['Charlie', 'Bob', 'Alice']);
});

test('sortBy ignores disallowed column', function () {
    Organization::create(['name' => 'Alice']);
    Organization::create(['name' => 'Bob']);

    $results = OrganizationQueryBuilder::make()
        ->sortBy('metadata', 'asc')
        ->get();

    expect($results)->toHaveCount(2);
});

test('latest orders by created_at descending', function () {
    $first = Organization::create(['name' => 'First']);
    Organization::query()->where('id', $first->id)->update(['created_at' => now()->subMinute()]);

    $second = Organization::create(['name' => 'Second']);

    $results = OrganizationQueryBuilder::make()
        ->latest()
        ->get();

    expect($results->first()->name)->toBe('Second');
});

test('firstOrFail returns first matching record', function () {
    $org = Organization::create(['name' => 'Test']);

    $result = OrganizationQueryBuilder::make()
        ->whereKey($org->key)
        ->firstOrFail();

    expect($result->id)->toBe($org->id);
});

test('firstOrFail throws when no match', function () {
    OrganizationQueryBuilder::make()
        ->whereKey('org_nonexistent')
        ->firstOrFail();
})->throws(ModelNotFoundException::class);

test('first returns null when no match', function () {
    $result = OrganizationQueryBuilder::make()
        ->whereKey('org_nonexistent')
        ->first();

    expect($result)->toBeNull();
});

test('get returns collection of results', function () {
    Organization::create(['name' => 'A']);
    Organization::create(['name' => 'B']);

    $results = OrganizationQueryBuilder::make()->get();

    expect($results)->toHaveCount(2)
        ->and($results)->toBeInstanceOf(Collection::class);
});

test('paginate returns paginated results', function () {
    for ($i = 0; $i < 5; $i++) {
        Organization::create(['name' => "Org {$i}"]);
    }

    $paginated = OrganizationQueryBuilder::make()->paginate(2);

    expect($paginated->total())->toBe(5)
        ->and($paginated->perPage())->toBe(2)
        ->and($paginated->items())->toHaveCount(2);
});

test('paginate defaults to 20 per page', function () {
    Organization::create(['name' => 'Test']);

    $paginated = OrganizationQueryBuilder::make()->paginate();

    expect($paginated->perPage())->toBe(20);
});

test('getQuery returns underlying builder', function () {
    $query = OrganizationQueryBuilder::make()->getQuery();
    expect($query)->toBeInstanceOf(Builder::class);
});

test('methods can be chained together', function () {
    Organization::create(['name' => 'Acme Corporation']);
    Organization::create(['name' => 'Beta Industries']);

    $results = OrganizationQueryBuilder::make()
        ->search('Acme')
        ->sortBy('name', 'asc')
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->name)->toBe('Acme Corporation');
});
