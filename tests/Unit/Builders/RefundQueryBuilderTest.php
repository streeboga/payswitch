<?php

declare(strict_types=1);

use App\Builders\RefundQueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Streeboga\PaymentData\Enums\RefundStatus;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\Models\Refund;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $org = Organization::create(['name' => 'Test Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'Merchant']);
    $this->otherMerchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'Other']);
    $this->paymentIntent = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'amount' => 1000,
        'currency' => 'usd',
        'status' => 'requires_payment_method',
        'capture_method' => 'automatic',
        'authentication_type' => 'no_three_ds',
    ]);
});

function createRefund(array $attributes = []): Refund
{
    return Refund::create(array_merge([
        'payment_intent_id' => test()->paymentIntent->id,
        'merchant_account_id' => test()->merchant->id,
        'amount' => 500,
        'currency' => 'usd',
        'status' => 'pending',
    ], $attributes));
}

test('make returns a new instance', function () {
    expect(RefundQueryBuilder::make())->toBeInstanceOf(RefundQueryBuilder::class);
});

test('forMerchant filters by merchant_account_id', function () {
    createRefund(['merchant_account_id' => $this->merchant->id]);
    createRefund(['merchant_account_id' => $this->otherMerchant->id, 'payment_intent_id' => $this->paymentIntent->id]);

    $results = RefundQueryBuilder::make()
        ->forMerchant($this->merchant->id)
        ->getQuery()
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->merchant_account_id)->toBe($this->merchant->id);
});

test('forPaymentIntent filters by payment_intent_id', function () {
    $otherPi = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'amount' => 2000,
        'currency' => 'usd',
        'status' => 'requires_payment_method',
        'capture_method' => 'automatic',
        'authentication_type' => 'no_three_ds',
    ]);

    createRefund(['payment_intent_id' => $this->paymentIntent->id]);
    createRefund(['payment_intent_id' => $otherPi->id]);

    $results = RefundQueryBuilder::make()
        ->forPaymentIntent($this->paymentIntent->id)
        ->getQuery()
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->payment_intent_id)->toBe($this->paymentIntent->id);
});

test('whereKey filters by key', function () {
    $refund = createRefund();
    createRefund();

    $result = RefundQueryBuilder::make()
        ->whereKey($refund->key)
        ->first();

    expect($result)->not->toBeNull()
        ->and($result->id)->toBe($refund->id);
});

test('whereKey returns nothing for non-existent key', function () {
    createRefund();

    $result = RefundQueryBuilder::make()
        ->whereKey('re_nonexistent')
        ->first();

    expect($result)->toBeNull();
});

test('withStatus filters by string status', function () {
    createRefund(['status' => 'succeeded']);
    createRefund(['status' => 'failed']);

    $results = RefundQueryBuilder::make()
        ->withStatus('succeeded')
        ->getQuery()
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->status)->toBe(RefundStatus::Succeeded);
});

test('withStatus filters by enum status', function () {
    createRefund(['status' => 'succeeded']);
    createRefund(['status' => 'failed']);

    $results = RefundQueryBuilder::make()
        ->withStatus(RefundStatus::Succeeded)
        ->getQuery()
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->status)->toBe(RefundStatus::Succeeded);
});

test('withStatus with enum passes string value not enum object to query', function () {
    $builder = RefundQueryBuilder::make()->withStatus(RefundStatus::Succeeded);
    $query = $builder->getQuery();

    // Get the wheres array from the underlying query builder
    $wheres = $query->getQuery()->wheres;
    $lastWhere = end($wheres);

    // The value stored in the where clause should be a plain string,
    // not a BackedEnum object. If instanceof is replaced with false,
    // the enum object is passed and Laravel may or may not resolve it.
    expect($lastWhere['value'])->toBe('succeeded')
        ->and($lastWhere['value'])->toBeString()
        ->and($lastWhere['value'])->not->toBeInstanceOf(RefundStatus::class);
});

test('createdBetween filters by date range', function () {
    $refund = createRefund();
    $refund->forceFill(['created_at' => '2025-01-15 12:00:00'])->save();

    $old = createRefund();
    $old->forceFill(['created_at' => '2024-01-01 12:00:00'])->save();

    $results = RefundQueryBuilder::make()
        ->createdBetween('2025-01-01', '2025-12-31')
        ->getQuery()
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->id)->toBe($refund->id);
});

test('search filters by key partial match', function () {
    $refund = createRefund();
    $refund->forceFill(['key' => 're_searchablekey123'])->save();

    createRefund(['reason' => 'something else']);

    $results = RefundQueryBuilder::make()
        ->search('searchablekey')
        ->getQuery()
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->id)->toBe($refund->id);
});

test('search filters by reason', function () {
    createRefund(['reason' => 'duplicate charge']);
    createRefund(['reason' => 'other reason']);

    $results = RefundQueryBuilder::make()
        ->search('duplicate')
        ->getQuery()
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->reason)->toBe('duplicate charge');
});

test('search filters by error_message', function () {
    createRefund(['error_message' => 'insufficient funds']);
    createRefund(['error_message' => 'other error']);

    $results = RefundQueryBuilder::make()
        ->search('insufficient')
        ->getQuery()
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->error_message)->toBe('insufficient funds');
});

test('search with no match returns empty', function () {
    createRefund(['reason' => 'some reason']);

    $results = RefundQueryBuilder::make()
        ->search('nonexistent_xyz_term')
        ->getQuery()
        ->get();

    expect($results)->toHaveCount(0);
});

test('search escapes percent in search term', function () {
    $builder = RefundQueryBuilder::make()->search('100%off');
    $bindings = $builder->getQuery()->getBindings();

    // The % in the search term should be escaped to \%, not left as a wildcard
    // The wrapping %...% wildcards are the only real wildcards
    expect($bindings)->toContain('%100\%off%');
});

test('search escapes underscore in search term', function () {
    $builder = RefundQueryBuilder::make()->search('code_x');
    $bindings = $builder->getQuery()->getBindings();

    // The _ in the search term should be escaped to \_, not left as a wildcard
    expect($bindings)->toContain('%code\_x%');
});

test('search escapes both percent and underscore', function () {
    $builder = RefundQueryBuilder::make()->search('a%b_c');
    $bindings = $builder->getQuery()->getBindings();

    // Both special characters should be escaped
    expect($bindings)->toContain('%a\%b\_c%');
});

test('sortBy orders by created_at ascending', function () {
    $r1 = createRefund();
    $r1->forceFill(['created_at' => '2025-03-01'])->save();

    $r2 = createRefund();
    $r2->forceFill(['created_at' => '2025-01-01'])->save();

    $results = RefundQueryBuilder::make()
        ->sortBy('created_at')
        ->getQuery()
        ->get();

    expect($results->first()->id)->toBe($r2->id)
        ->and($results->last()->id)->toBe($r1->id);
});

test('sortBy orders by created_at descending', function () {
    $r1 = createRefund();
    $r1->forceFill(['created_at' => '2025-03-01'])->save();

    $r2 = createRefund();
    $r2->forceFill(['created_at' => '2025-01-01'])->save();

    $results = RefundQueryBuilder::make()
        ->sortBy('-created_at')
        ->getQuery()
        ->get();

    expect($results->first()->id)->toBe($r1->id)
        ->and($results->last()->id)->toBe($r2->id);
});

test('sortBy orders by status', function () {
    createRefund(['status' => 'succeeded']);
    createRefund(['status' => 'failed']);

    $results = RefundQueryBuilder::make()
        ->sortBy('status')
        ->getQuery()
        ->get();

    expect($results->first()->status)->toBe(RefundStatus::Failed)
        ->and($results->last()->status)->toBe(RefundStatus::Succeeded);
});

test('sortBy ignores disallowed column', function () {
    createRefund();
    createRefund();

    $results = RefundQueryBuilder::make()
        ->sortBy('currency')
        ->getQuery()
        ->get();

    expect($results)->toHaveCount(2);
});

test('with eager loads relations', function () {
    createRefund();

    $result = RefundQueryBuilder::make()
        ->with('paymentIntent')
        ->first();

    expect($result->relationLoaded('paymentIntent'))->toBeTrue();
});

test('latest orders by created_at descending', function () {
    $r1 = createRefund();
    $r1->forceFill(['created_at' => '2025-01-01'])->save();

    $r2 = createRefund();
    $r2->forceFill(['created_at' => '2025-06-01'])->save();

    $results = RefundQueryBuilder::make()
        ->latest()
        ->getQuery()
        ->get();

    expect($results->first()->id)->toBe($r2->id)
        ->and($results->last()->id)->toBe($r1->id);
});

test('paginate returns paginated results', function () {
    for ($i = 0; $i < 5; $i++) {
        createRefund();
    }

    $paginated = RefundQueryBuilder::make()->paginate(2);

    expect($paginated->total())->toBe(5)
        ->and($paginated->perPage())->toBe(2)
        ->and($paginated->items())->toHaveCount(2);
});

test('paginate defaults to 20 per page', function () {
    createRefund();

    $paginated = RefundQueryBuilder::make()->paginate();

    expect($paginated->perPage())->toBe(20);
});

test('sumAmount returns sum of amounts as integer', function () {
    createRefund(['amount' => 100]);
    createRefund(['amount' => 250]);

    $sum = RefundQueryBuilder::make()->sumAmount();

    expect($sum)->toBe(350)
        ->and($sum)->toBeInt();
});

test('sumAmount returns zero as integer when no records', function () {
    $sum = RefundQueryBuilder::make()
        ->forMerchant(99999)
        ->sumAmount();

    // sum() with no records returns 0 (numeric). The (int) cast ensures it's int.
    expect($sum)->toBe(0)
        ->and($sum)->toBeInt()
        ->and(gettype($sum))->toBe('integer');
});

test('firstOrFail throws when no match', function () {
    RefundQueryBuilder::make()
        ->whereKey('re_nonexistent')
        ->firstOrFail();
})->throws(ModelNotFoundException::class);

test('first returns null when no match', function () {
    $result = RefundQueryBuilder::make()
        ->whereKey('re_nonexistent')
        ->first();

    expect($result)->toBeNull();
});

test('getQuery returns underlying builder', function () {
    expect(RefundQueryBuilder::make()->getQuery())->toBeInstanceOf(Builder::class);
});
