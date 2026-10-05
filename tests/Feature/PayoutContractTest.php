<?php

declare(strict_types=1);

use App\Contracts\PayoutAuthorizationVerifier;
use App\Contracts\PayoutChannel;
use App\DataTransferObjects\Payout\CreatePayoutData;
use App\DataTransferObjects\Payout\PayoutAuthorization;
use App\DataTransferObjects\Payout\PayoutExternalResult;
use App\Enums\PayoutStatus;
use App\Models\Payout;
use App\Models\PayoutAttempt;
use App\Models\PayoutRecipientVersion;
use App\Services\Payout\PayoutChannels;
use App\Services\Payout\PayoutExecutionService;
use App\Services\Payout\PayoutResultService;
use App\Services\Payout\PayoutService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Streeboga\PaymentData\Exceptions\PaymentException;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;

covers(PayoutService::class, PayoutExecutionService::class, PayoutResultService::class);
uses(RefreshDatabase::class);

final class ContractPayoutChannel implements PayoutChannel
{
    public int $executions = 0;

    public int $syncs = 0;

    public ?PayoutExternalResult $next = null;

    public ?string $attemptKey = null;

    public function assertSupported(Payout $payout, PayoutRecipientVersion $recipient): void {}

    public function execute(Payout $payout, PayoutAttempt $attempt, PayoutRecipientVersion $recipient): ?PayoutExternalResult
    {
        $this->executions++;
        $this->attemptKey = $attempt->key;
        // The durable claim must already exist at the point of external submission.
        expect(PayoutAttempt::where('payout_id', $payout->id)->count())->toBe(1);
        expect(Payout::findOrFail($payout->id)->status)->toBe(PayoutStatus::Processing);
        throw new RuntimeException('Lost response after provider acceptance');
    }

    public function sync(Payout $payout, PayoutAttempt $attempt): ?PayoutExternalResult
    {
        $this->syncs++;
        expect($attempt->key)->toBe($this->attemptKey);

        return $this->next;
    }
}

beforeEach(function () {
    Bus::fake();
    $org = Organization::create(['name' => 'Payout contracts']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'merchant']);
    $this->verifier = new class implements PayoutAuthorizationVerifier
    {
        public bool $valid = true;

        public string $reserve = 'reserve-1';

        public function verify(Payout $payout): PayoutAuthorization
        {
            if (! $this->valid) {
                throw new PaymentException('No live reserve', 'no_reserve', 'invalid_request_error', 409);
            }

            return new PayoutAuthorization('genesis-approval', $this->reserve, 'finance-actor');
        }
    };
    app()->instance(PayoutAuthorizationVerifier::class, $this->verifier);
    $this->channel = new ContractPayoutChannel;
    app()->instance(PayoutChannels::class, new PayoutChannels(['contract-test' => $this->channel]));
    $this->service = app(PayoutService::class);
    $this->recipient = $this->service->recordVerifiedRecipient($this->merchant->id, 'project-A', 'seller-1', 'kyb-evidence', ['account' => 'secret-account'], 'receive_payout');
    $this->data = new CreatePayoutData('project-A', 'operation-1', 'settlement-1', 'stable-key', $this->recipient->key, '12300000000', 'RUB', 8, 'contract-test', 'Agreed settlement');
    $this->payout = $this->service->create($this->data, $this->merchant->id);
});

function payoutEvidence(PayoutStatus $status = PayoutStatus::Succeeded, string $eventId = 'bank-success'): PayoutExternalResult
{
    return new PayoutExternalResult($eventId, PayoutAttempt::where('payout_id', test()->payout->id)->latest('id')->firstOrFail()->key, $status, $status === PayoutStatus::Reversed ? 'bank-reversal-1' : 'bank-transfer-1', test()->payout->amount, 'RUB', test()->recipient->key, 8, 'statement:item-1', CarbonImmutable::parse('2026-10-04T00:00:00Z'), $status === PayoutStatus::Reversed ? 'bank-transfer-1' : null);
}

test('ten identical commands retain one business obligation and recipient snapshot', function () {
    for ($i = 0; $i < 10; $i++) {
        expect($this->service->create($this->data, $this->merchant->id)->key)->toBe($this->payout->key);
    }
    expect(Payout::count())->toBe(1)->and(PayoutAttempt::count())->toBe(0);
    $raw = DB::table('payout_recipient_versions')->value('details');
    expect($raw)->not->toContain('secret-account');
    expect($this->recipient->toArray())->not->toHaveKey('details');
});

test('changed amount or recipient with same identity is rejected', function () {
    $changed = new CreatePayoutData('project-A', 'operation-1', 'settlement-1', 'stable-key', $this->recipient->key, '12300000001', 'RUB', 8, 'contract-test', 'Agreed settlement');
    expect(fn () => $this->service->create($changed, $this->merchant->id))->toThrow(PaymentException::class, 'conflict');
    $newRecipient = $this->service->recordVerifiedRecipient($this->merchant->id, 'project-A', 'seller-1', 'new-verification', ['account' => 'changed-account'], 'receive_payout');
    $changed = new CreatePayoutData('project-A', 'operation-1', 'settlement-1', 'stable-key', $newRecipient->key, '12300000000', 'RUB', 8, 'contract-test', 'Agreed settlement');
    expect(fn () => $this->service->create($changed, $this->merchant->id))->toThrow(PaymentException::class, 'conflict');
    expect($this->payout->refresh()->recipient_version_id)->toBe($this->recipient->id);
});

test('recipient versions cannot be mutated after approval', function () {
    $this->service->confirm($this->payout->key, $this->merchant->id, 'project-A');
    expect(fn () => $this->recipient->update(['details' => ['account' => 'replacement']]))->toThrow(LogicException::class);
});

test('cross project and merchant cannot approve or execute a payout', function () {
    expect(fn () => $this->service->confirm($this->payout->key, $this->merchant->id, 'other-project'))->toThrow(ModelNotFoundException::class);
    expect(fn () => app(PayoutExecutionService::class)->execute($this->payout->key, $this->merchant->id + 1, 'project-A'))->toThrow(ModelNotFoundException::class);
    expect($this->channel->executions)->toBe(0);
});

test('unconfigured genesis authorization and bank channel fail closed', function () {
    app()->forgetInstance(PayoutAuthorizationVerifier::class);
    expect(fn () => app(PayoutService::class)->confirm($this->payout->key, $this->merchant->id, 'project-A'))->toThrow(PaymentException::class, 'not configured');
    app()->instance(PayoutAuthorizationVerifier::class, $this->verifier);
    $this->service->confirm($this->payout->key, $this->merchant->id, 'project-A');
    app()->instance(PayoutChannels::class, new PayoutChannels);
    expect(fn () => app(PayoutExecutionService::class)->execute($this->payout->key, $this->merchant->id, 'project-A'))->toThrow(PaymentException::class, 'operator approval');
    expect(PayoutAttempt::count())->toBe(0);
});

test('reserve is rechecked before sending and changed approval is rejected', function () {
    $this->service->confirm($this->payout->key, $this->merchant->id, 'project-A');
    $this->verifier->reserve = 'replacement-reserve';
    expect(fn () => app(PayoutExecutionService::class)->execute($this->payout->key, $this->merchant->id, 'project-A'))->toThrow(PaymentException::class, 'changed');
    expect(PayoutAttempt::count())->toBe(0);
});

test('timeout retains one attempt and restart syncs without failover', function () {
    $this->service->confirm($this->payout->key, $this->merchant->id, 'project-A');
    $executor = app(PayoutExecutionService::class);
    for ($i = 0; $i < 10; $i++) {
        $payout = $executor->execute($this->payout->key, $this->merchant->id, 'project-A');
        expect($payout->status)->toBe(PayoutStatus::PendingConfirmation);
    }
    expect($this->channel->executions)->toBe(1)->and(PayoutAttempt::count())->toBe(1);
    expect(fn () => $this->service->cancel($this->payout->key, $this->merchant->id, 'project-A'))->toThrow(PaymentException::class);
    $this->channel->next = payoutEvidence();
    $payout = app(PayoutExecutionService::class)->sync($this->payout->key, $this->merchant->id, 'project-A');
    expect($payout->status)->toBe(PayoutStatus::Succeeded)->and($this->channel->executions)->toBe(1);
    for ($i = 0; $i < 10; $i++) {
        app(PayoutResultService::class)->apply($this->payout->key, $this->merchant->id, 'project-A', payoutEvidence());
    }
    expect(DB::table('payout_external_events')->count())->toBe(1);
    $this->assertDatabaseCount('webhook_events', 1);
});

test('bank signature is pending and reversal preserves success evidence', function () {
    $this->service->confirm($this->payout->key, $this->merchant->id, 'project-A');
    app(PayoutExecutionService::class)->execute($this->payout->key, $this->merchant->id, 'project-A');
    $results = app(PayoutResultService::class);
    $awaiting = $results->apply($this->payout->key, $this->merchant->id, 'project-A', payoutEvidence(PayoutStatus::AwaitingBankSignature, 'bank-signature'));
    expect($awaiting->succeeded_at)->toBeNull();
    $results->apply($this->payout->key, $this->merchant->id, 'project-A', payoutEvidence());
    $reversed = $results->apply($this->payout->key, $this->merchant->id, 'project-A', payoutEvidence(PayoutStatus::Reversed, 'bank-reversal'));
    expect($reversed->status)->toBe(PayoutStatus::Reversed)->and($reversed->succeeded_at)->not->toBeNull();
    $this->assertDatabaseHas('payout_external_events', ['status' => 'succeeded']);
    $this->assertDatabaseHas('payout_external_events', ['status' => 'reversed']);
    $this->assertDatabaseCount('payout_external_events', 3);
});

test('external amount and event replay conflicts cannot finalize payout', function () {
    $this->service->confirm($this->payout->key, $this->merchant->id, 'project-A');
    app(PayoutExecutionService::class)->execute($this->payout->key, $this->merchant->id, 'project-A');
    $bad = new PayoutExternalResult('event-1', PayoutAttempt::where('payout_id', $this->payout->id)->latest('id')->firstOrFail()->key, PayoutStatus::Succeeded, 'bank-1', 1, 'RUB', $this->recipient->key, 8, 'statement:1', CarbonImmutable::now());
    expect(fn () => app(PayoutResultService::class)->apply($this->payout->key, $this->merchant->id, 'project-A', $bad))->toThrow(PaymentException::class, 'does not match');
    $this->assertDatabaseCount('payout_external_events', 0);
    $results = app(PayoutResultService::class);
    $results->apply($this->payout->key, $this->merchant->id, 'project-A', payoutEvidence());
    expect(fn () => $results->apply($this->payout->key, $this->merchant->id, 'project-A', payoutEvidence(PayoutStatus::Reversed, 'bank-success')))->toThrow(PaymentException::class, 'conflict');
});

test('only proved nonexecution and new Genesis approval allow another attempt of the same obligation', function () {
    $key = $this->payout->key;
    $merchant = $this->merchant->id;
    $this->service->confirm($key, $merchant, 'project-A');
    app(PayoutExecutionService::class)->execute($key, $merchant, 'project-A');
    $first = PayoutAttempt::where('payout_id', $this->payout->id)->firstOrFail();
    $unproved = new PayoutExternalResult('bank-failure', $first->key, PayoutStatus::Failed, 'bank-transfer-1', $this->payout->amount, 'RUB', $this->recipient->key, 8, 'statement:failure', CarbonImmutable::now());
    expect(fn () => app(PayoutResultService::class)->apply($key, $merchant, 'project-A', $unproved))->toThrow(PaymentException::class, 'proof of non-execution');
    $proved = new PayoutExternalResult('bank-failure', $first->key, PayoutStatus::Failed, 'bank-transfer-1', $this->payout->amount, 'RUB', $this->recipient->key, 8, 'statement:failure', CarbonImmutable::now(), null, true);
    app(PayoutResultService::class)->apply($key, $merchant, 'project-A', $proved);
    expect(fn () => app(PayoutExecutionService::class)->execute($key, $merchant, 'project-A'))->toThrow(PaymentException::class);
    $this->service->confirm($key, $merchant, 'project-A');
    app(PayoutExecutionService::class)->execute($key, $merchant, 'project-A');
    expect(Payout::count())->toBe(1)->and(PayoutAttempt::count())->toBe(2)->and($this->channel->executions)->toBe(2);
    $stale = new PayoutExternalResult('old-late-success', $first->key, PayoutStatus::Succeeded, 'bank-transfer-1', $this->payout->amount, 'RUB', $this->recipient->key, 8, 'statement:old', CarbonImmutable::now());
    expect(fn () => app(PayoutResultService::class)->apply($key, $merchant, 'project-A', $stale))->toThrow(PaymentException::class, 'original attempt');
});

test('generic party verification cannot authorize payout recipients', function () {
    expect(fn () => $this->service->recordVerifiedRecipient($this->merchant->id, 'project-A', 'seller-1', 'generic-kyb', ['account' => 'other'], 'sell'))->toThrow(PaymentException::class, 'Verified recipient');
});

test('one bank transfer cannot settle two payout instructions', function () {
    $this->service->confirm($this->payout->key, $this->merchant->id, 'project-A');
    app(PayoutExecutionService::class)->execute($this->payout->key, $this->merchant->id, 'project-A');
    app(PayoutResultService::class)->apply($this->payout->key, $this->merchant->id, 'project-A', payoutEvidence());
    $second = $this->service->create(new CreatePayoutData('project-A', 'operation-2', 'settlement-2', 'key-2', $this->recipient->key, '12300000000', 'RUB', 8, 'contract-test', 'Agreed settlement'), $this->merchant->id);
    $this->service->confirm($second->key, $this->merchant->id, 'project-A');
    app(PayoutExecutionService::class)->execute($second->key, $this->merchant->id, 'project-A');
    $this->payout = $second;
    expect(fn () => app(PayoutResultService::class)->apply($second->key, $this->merchant->id, 'project-A', payoutEvidence()))->toThrow(PaymentException::class, 'already belongs');
    expect($second->refresh()->status)->toBe(PayoutStatus::PendingConfirmation);
});

test('у выплат нет ни одного маршрута', function () {
    $uris = collect(app('router')->getRoutes()->getRoutes())->map(fn ($route) => $route->uri());

    expect($uris->filter(fn (string $uri) => str_contains($uri, 'payout'))->all())->toBe([]);
});
