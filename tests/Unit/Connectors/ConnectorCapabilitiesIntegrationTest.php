<?php

declare(strict_types=1);

use Streeboga\PaymentConnectors\ConnectorCapabilities;
use Streeboga\PaymentConnectors\DirectMethod;
use Streeboga\PaymentConnectors\Drivers\CloudPaymentsConnector;
use Streeboga\PaymentConnectors\Drivers\StripeConnector;
use Streeboga\PaymentConnectors\Drivers\TestConnector;
use Streeboga\PaymentConnectors\Drivers\YooKassaConnector;
use Streeboga\PaymentData\Enums\AmountUnit;
use Streeboga\PaymentData\Enums\SessionResultType;

// 1. All 4 connectors return ConnectorCapabilities instance

test('all connectors return ConnectorCapabilities instance', function () {
    expect(YooKassaConnector::capabilities())->toBeInstanceOf(ConnectorCapabilities::class)
        ->and(CloudPaymentsConnector::capabilities())->toBeInstanceOf(ConnectorCapabilities::class)
        ->and(StripeConnector::capabilities())->toBeInstanceOf(ConnectorCapabilities::class)
        ->and(TestConnector::capabilities())->toBeInstanceOf(ConnectorCapabilities::class);
});

// 2. All have 'en' key in displayName

test('all connectors have en key in defaultDisplayName', function () {
    $connectors = [
        YooKassaConnector::capabilities(),
        CloudPaymentsConnector::capabilities(),
        StripeConnector::capabilities(),
        TestConnector::capabilities(),
    ];

    foreach ($connectors as $caps) {
        expect($caps->defaultDisplayName)->toHaveKey('en');
    }
});

// 3. All have logoPath string

test('all connectors have non-empty logoPath', function () {
    $connectors = [
        YooKassaConnector::capabilities(),
        CloudPaymentsConnector::capabilities(),
        StripeConnector::capabilities(),
        TestConnector::capabilities(),
    ];

    foreach ($connectors as $caps) {
        expect($caps->logoPath)->toBeString()->not->toBeEmpty();
    }
});

// 4. All have fallbackSessionType

test('all connectors have fallbackSessionType', function () {
    $connectors = [
        YooKassaConnector::capabilities(),
        CloudPaymentsConnector::capabilities(),
        StripeConnector::capabilities(),
        TestConnector::capabilities(),
    ];

    foreach ($connectors as $caps) {
        expect($caps->fallbackSessionType)->toBeInstanceOf(SessionResultType::class);
    }
});

// 5. YooKassa supports direct card AND sbp

test('YooKassa supports direct card and sbp', function () {
    $caps = YooKassaConnector::capabilities();

    expect($caps->supportsDirectMethod('card'))->toBeTrue()
        ->and($caps->supportsDirectMethod('sbp'))->toBeTrue()
        ->and($caps->getDirectMethod('card'))->toBeInstanceOf(DirectMethod::class)
        ->and($caps->getDirectMethod('sbp'))->toBeInstanceOf(DirectMethod::class)
        ->and($caps->getDirectMethod('card')->sessionType)->toBe(SessionResultType::ServerRedirect)
        ->and($caps->getDirectMethod('sbp')->sessionType)->toBe(SessionResultType::ServerRedirect)
        ->and($caps->amountUnit)->toBe(AmountUnit::Rubles);
});

// 6. CloudPayments uses EmbeddedWidget type

test('CloudPayments uses EmbeddedWidget session type', function () {
    $caps = CloudPaymentsConnector::capabilities();

    expect($caps->fallbackSessionType)->toBe(SessionResultType::EmbeddedWidget)
        ->and($caps->supportsDirectMethod('card'))->toBeTrue()
        ->and($caps->supportsDirectMethod('sbp'))->toBeTrue()
        ->and($caps->getDirectMethod('card')->sessionType)->toBe(SessionResultType::EmbeddedWidget)
        ->and($caps->getDirectMethod('sbp')->sessionType)->toBe(SessionResultType::EmbeddedWidget)
        ->and($caps->amountUnit)->toBe(AmountUnit::Rubles);
});

// 7. Stripe supports direct card but NOT sbp

test('Stripe supports direct card but not sbp', function () {
    $caps = StripeConnector::capabilities();

    expect($caps->supportsDirectMethod('card'))->toBeTrue()
        ->and($caps->supportsDirectMethod('sbp'))->toBeFalse()
        ->and($caps->getDirectMethod('card')->sessionType)->toBe(SessionResultType::ServerRedirect)
        ->and($caps->fallbackSessionType)->toBe(SessionResultType::ServerRedirect)
        ->and($caps->amountUnit)->toBe(AmountUnit::MinorUnits);
});

// 8. Test connector supports direct card

test('Test connector supports direct card', function () {
    $caps = TestConnector::capabilities();

    expect($caps->supportsDirectMethod('card'))->toBeTrue()
        ->and($caps->getDirectMethod('card')->sessionType)->toBe(SessionResultType::ServerRedirect)
        ->and($caps->fallbackSessionType)->toBe(SessionResultType::ServerRedirect)
        ->and($caps->amountUnit)->toBe(AmountUnit::MinorUnits)
        ->and($caps->defaultDisplayName['ru'])->toBe('Тестовый')
        ->and($caps->defaultDisplayName['en'])->toBe('Test');
});

// Verify specific display names and logo paths

test('YooKassa has correct display name and logo', function () {
    $caps = YooKassaConnector::capabilities();

    expect($caps->defaultDisplayName['ru'])->toBe('ЮKassa')
        ->and($caps->defaultDisplayName['en'])->toBe('YooKassa')
        ->and($caps->logoPath)->toBe('/logos/yookassa.svg');
});

test('CloudPayments has correct display name and logo', function () {
    $caps = CloudPaymentsConnector::capabilities();

    expect($caps->defaultDisplayName['ru'])->toBe('CloudPayments')
        ->and($caps->defaultDisplayName['en'])->toBe('CloudPayments')
        ->and($caps->logoPath)->toBe('/logos/cloudpayments.svg');
});

test('Stripe has correct display name and logo', function () {
    $caps = StripeConnector::capabilities();

    expect($caps->defaultDisplayName['ru'])->toBe('Stripe')
        ->and($caps->defaultDisplayName['en'])->toBe('Stripe')
        ->and($caps->logoPath)->toBe('/logos/stripe.svg');
});

test('Test connector has correct display name and logo', function () {
    $caps = TestConnector::capabilities();

    expect($caps->defaultDisplayName['ru'])->toBe('Тестовый')
        ->and($caps->defaultDisplayName['en'])->toBe('Test')
        ->and($caps->logoPath)->toBe('/logos/test.svg');
});
