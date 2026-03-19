<?php

declare(strict_types=1);

use App\Enums\ApiKeyType;
use App\Enums\CacheKey;
use App\Enums\CacheTtl;
use App\Enums\ConnectorName;
use App\Enums\DisputeStatus;
use App\Enums\DisputeType;
use App\Enums\PaymentAttemptStatus;
use App\Enums\RoutingRuleType;
use App\Enums\UserRole;
use App\Enums\WebhookEventType;

uses()->group('unit');

// --- ApiKeyType ---

test('ApiKeyType has labels, colors, and icons for all cases', function () {
    foreach (ApiKeyType::cases() as $case) {
        expect($case->getLabel())->toBeString()->not->toBeEmpty();
        expect($case->getColor())->toBeString()->not->toBeEmpty();
        expect($case->getIcon())->toBeString()->not->toBeEmpty();
    }
});

test('ApiKeyType values contain expected entries', function () {
    expect(ApiKeyType::values())->toContain('admin', 'secret', 'publishable');
});

test('ApiKeyType has exactly 3 cases', function () {
    expect(ApiKeyType::cases())->toHaveCount(3);
});

// --- ConnectorName ---

test('ConnectorName has labels, colors, and icons for all cases', function () {
    foreach (ConnectorName::cases() as $case) {
        expect($case->getLabel())->toBeString()->not->toBeEmpty();
        expect($case->getColor())->toBeString()->not->toBeEmpty();
        expect($case->getIcon())->toBeString()->not->toBeEmpty();
    }
});

test('ConnectorName values contain expected entries', function () {
    expect(ConnectorName::values())->toContain('stripe', 'cloudpayments', 'test');
});

test('ConnectorName options returns label-keyed array', function () {
    $options = ConnectorName::options();
    expect($options)->toBeArray()->toHaveCount(count(ConnectorName::cases()));
    foreach (ConnectorName::cases() as $case) {
        expect($options)->toHaveKey($case->value);
        expect($options[$case->value])->toBe($case->getLabel());
    }
});

// --- DisputeStatus ---

test('DisputeStatus has labels, colors, and icons for all cases', function () {
    foreach (DisputeStatus::cases() as $case) {
        expect($case->getLabel())->toBeString()->not->toBeEmpty();
        expect($case->getColor())->toBeString()->not->toBeEmpty();
        expect($case->getIcon())->toBeString()->not->toBeEmpty();
    }
});

test('DisputeStatus values contain expected entries', function () {
    expect(DisputeStatus::values())->toContain('opened', 'evidence_required', 'resolved', 'lost', 'won');
});

test('DisputeStatus options returns label-keyed array', function () {
    $options = DisputeStatus::options();
    expect($options)->toBeArray()->toHaveCount(count(DisputeStatus::cases()));
    foreach (DisputeStatus::cases() as $case) {
        expect($options[$case->value])->toBe($case->getLabel());
    }
});

test('DisputeStatus terminal statuses are correctly identified', function () {
    expect(DisputeStatus::Resolved->isTerminal())->toBeTrue();
    expect(DisputeStatus::Lost->isTerminal())->toBeTrue();
    expect(DisputeStatus::Won->isTerminal())->toBeTrue();
    expect(DisputeStatus::Opened->isTerminal())->toBeFalse();
    expect(DisputeStatus::EvidenceRequired->isTerminal())->toBeFalse();
});

test('DisputeStatus active statuses are opposite of terminal', function () {
    foreach (DisputeStatus::cases() as $case) {
        expect($case->isActive())->toBe(! $case->isTerminal());
    }
});

test('DisputeStatus canTransitionTo allows valid transitions from Opened', function () {
    expect(DisputeStatus::Opened->canTransitionTo(DisputeStatus::EvidenceRequired))->toBeTrue();
    expect(DisputeStatus::Opened->canTransitionTo(DisputeStatus::Resolved))->toBeTrue();
    expect(DisputeStatus::Opened->canTransitionTo(DisputeStatus::Lost))->toBeTrue();
    expect(DisputeStatus::Opened->canTransitionTo(DisputeStatus::Won))->toBeTrue();
});

test('DisputeStatus canTransitionTo allows valid transitions from EvidenceRequired', function () {
    expect(DisputeStatus::EvidenceRequired->canTransitionTo(DisputeStatus::Resolved))->toBeTrue();
    expect(DisputeStatus::EvidenceRequired->canTransitionTo(DisputeStatus::Lost))->toBeTrue();
    expect(DisputeStatus::EvidenceRequired->canTransitionTo(DisputeStatus::Won))->toBeTrue();
    expect(DisputeStatus::EvidenceRequired->canTransitionTo(DisputeStatus::Opened))->toBeFalse();
});

test('DisputeStatus canTransitionTo blocks transitions from terminal statuses', function () {
    $terminalStatuses = [DisputeStatus::Resolved, DisputeStatus::Lost, DisputeStatus::Won];
    foreach ($terminalStatuses as $terminal) {
        foreach (DisputeStatus::cases() as $target) {
            expect($terminal->canTransitionTo($target))->toBeFalse();
        }
    }
});

// --- DisputeType ---

test('DisputeType has labels, colors, and icons for all cases', function () {
    foreach (DisputeType::cases() as $case) {
        expect($case->getLabel())->toBeString()->not->toBeEmpty();
        expect($case->getColor())->toBeString()->not->toBeEmpty();
        expect($case->getIcon())->toBeString()->not->toBeEmpty();
    }
});

test('DisputeType values contain expected entries', function () {
    expect(DisputeType::values())->toContain('chargeback', 'inquiry', 'fraud');
});

test('DisputeType options returns label-keyed array', function () {
    $options = DisputeType::options();
    expect($options)->toBeArray()->toHaveCount(count(DisputeType::cases()));
    foreach (DisputeType::cases() as $case) {
        expect($options[$case->value])->toBe($case->getLabel());
    }
});

// --- PaymentAttemptStatus ---

test('PaymentAttemptStatus has labels, colors, and icons for all cases', function () {
    foreach (PaymentAttemptStatus::cases() as $case) {
        expect($case->getLabel())->toBeString()->not->toBeEmpty();
        expect($case->getColor())->toBeString()->not->toBeEmpty();
        expect($case->getIcon())->toBeString()->not->toBeEmpty();
    }
});

test('PaymentAttemptStatus values contain expected entries', function () {
    expect(PaymentAttemptStatus::values())->toContain('succeeded', 'failed', 'requires_action');
});

test('PaymentAttemptStatus options returns label-keyed array', function () {
    $options = PaymentAttemptStatus::options();
    expect($options)->toBeArray()->toHaveCount(count(PaymentAttemptStatus::cases()));
    foreach (PaymentAttemptStatus::cases() as $case) {
        expect($options[$case->value])->toBe($case->getLabel());
    }
});

// --- RoutingRuleType ---

test('RoutingRuleType has labels, colors, and icons for all cases', function () {
    foreach (RoutingRuleType::cases() as $case) {
        expect($case->getLabel())->toBeString()->not->toBeEmpty();
        expect($case->getColor())->toBeString()->not->toBeEmpty();
        expect($case->getIcon())->toBeString()->not->toBeEmpty();
    }
});

test('RoutingRuleType values contain expected entries', function () {
    expect(RoutingRuleType::values())->toContain('priority', 'rule_based', 'volume_split');
});

test('RoutingRuleType options returns label-keyed array', function () {
    $options = RoutingRuleType::options();
    expect($options)->toBeArray()->toHaveCount(count(RoutingRuleType::cases()));
    foreach (RoutingRuleType::cases() as $case) {
        expect($options[$case->value])->toBe($case->getLabel());
    }
});

// --- UserRole ---

test('UserRole has labels, colors, and icons for all cases', function () {
    foreach (UserRole::cases() as $case) {
        expect($case->getLabel())->toBeString()->not->toBeEmpty();
        expect($case->getColor())->toBeString()->not->toBeEmpty();
        expect($case->getIcon())->toBeString()->not->toBeEmpty();
    }
});

test('UserRole values contain expected entries', function () {
    expect(UserRole::values())->toContain('admin', 'operator', 'viewer');
});

test('UserRole options returns label-keyed array', function () {
    $options = UserRole::options();
    expect($options)->toBeArray()->toHaveCount(count(UserRole::cases()));
    foreach (UserRole::cases() as $case) {
        expect($options[$case->value])->toBe($case->getLabel());
    }
});

test('UserRole getWeight returns correct hierarchy', function () {
    expect(UserRole::Admin->getWeight())->toBeGreaterThan(UserRole::Operator->getWeight());
    expect(UserRole::Operator->getWeight())->toBeGreaterThan(UserRole::Viewer->getWeight());
});

test('UserRole getWeight returns positive integers', function () {
    foreach (UserRole::cases() as $case) {
        expect($case->getWeight())->toBeInt()->toBeGreaterThan(0);
    }
});

// --- WebhookEventType ---

test('WebhookEventType has labels, colors, and icons for all cases', function () {
    foreach (WebhookEventType::cases() as $case) {
        expect($case->getLabel())->toBeString()->not->toBeEmpty();
        expect($case->getColor())->toBeString()->not->toBeEmpty();
        expect($case->getIcon())->toBeString()->not->toBeEmpty();
    }
});

test('WebhookEventType values contain expected entries', function () {
    expect(WebhookEventType::values())->toContain(
        'payment_succeeded',
        'payment_captured',
        'payment_cancelled',
        'payment_authorized',
        'payment_status_changed',
        'refund_succeeded',
        'refund_failed',
    );
});

test('WebhookEventType options returns label-keyed array', function () {
    $options = WebhookEventType::options();
    expect($options)->toBeArray()->toHaveCount(count(WebhookEventType::cases()));
    foreach (WebhookEventType::cases() as $case) {
        expect($options[$case->value])->toBe($case->getLabel());
    }
});

test('WebhookEventType has exactly 7 cases', function () {
    expect(WebhookEventType::cases())->toHaveCount(7);
});

// --- CacheKey ---

test('CacheKey with method interpolates parameters', function () {
    expect(CacheKey::MerchantConnectors->with(42))->toBe('merchant:42:connectors');
    expect(CacheKey::MerchantAnalytics->with(42, 'daily'))->toBe('merchant:42:analytics:daily');
    expect(CacheKey::UserPreferences->with(7))->toBe('user:7:preferences');
    expect(CacheKey::ConnectorHealth->with(3, 'stripe'))->toBe('connector:3:health:stripe');
});

test('CacheKey getDefaultTtl returns a CacheTtl enum', function () {
    foreach (CacheKey::cases() as $case) {
        expect($case->getDefaultTtl())->toBeInstanceOf(CacheTtl::class);
    }
});

test('CacheKey getDefaultTtl returns expected ttl values', function () {
    expect(CacheKey::MerchantConnectors->getDefaultTtl())->toBe(CacheTtl::FiveMinutes);
    expect(CacheKey::MerchantAnalytics->getDefaultTtl())->toBe(CacheTtl::FifteenMinutes);
    expect(CacheKey::UserPreferences->getDefaultTtl())->toBe(CacheTtl::OneHour);
    expect(CacheKey::ConnectorHealth->getDefaultTtl())->toBe(CacheTtl::FiveMinutes);
});

test('CacheKey has exactly 4 cases', function () {
    expect(CacheKey::cases())->toHaveCount(4);
});

// --- CacheTtl ---

test('CacheTtl has labels for all cases', function () {
    foreach (CacheTtl::cases() as $case) {
        expect($case->getLabel())->toBeString()->not->toBeEmpty();
    }
});

test('CacheTtl values are positive integers in ascending order', function () {
    $values = array_map(fn (CacheTtl $ttl) => $ttl->value, CacheTtl::cases());
    $sorted = $values;
    sort($sorted);
    expect($values)->toBe($sorted);
    foreach ($values as $v) {
        expect($v)->toBeGreaterThan(0);
    }
});

test('CacheTtl has expected second values', function () {
    expect(CacheTtl::OneMinute->value)->toBe(60);
    expect(CacheTtl::FiveMinutes->value)->toBe(300);
    expect(CacheTtl::FifteenMinutes->value)->toBe(900);
    expect(CacheTtl::ThirtyMinutes->value)->toBe(1800);
    expect(CacheTtl::OneHour->value)->toBe(3600);
    expect(CacheTtl::SixHours->value)->toBe(21600);
    expect(CacheTtl::OneDay->value)->toBe(86400);
    expect(CacheTtl::OneWeek->value)->toBe(604800);
});

test('CacheTtl has exactly 8 cases', function () {
    expect(CacheTtl::cases())->toHaveCount(8);
});
