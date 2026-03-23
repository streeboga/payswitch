<?php

declare(strict_types=1);

use Streeboga\PaymentConnectors\ConnectorCapabilities;
use Streeboga\PaymentConnectors\DirectMethod;
use Streeboga\PaymentData\Enums\AmountUnit;
use Streeboga\PaymentData\Enums\SessionResultType;

test('ConnectorCapabilities can be created with all properties', function () {
    $capabilities = new ConnectorCapabilities(
        defaultDisplayName: ['en' => 'Test Gateway', 'ru' => 'Тестовый шлюз'],
        logoPath: '/images/connectors/test.svg',
        directMethods: [
            'card' => new DirectMethod(SessionResultType::ServerRedirect),
        ],
        fallbackSessionType: SessionResultType::ServerRedirect,
        amountUnit: AmountUnit::MinorUnits,
    );

    expect($capabilities->defaultDisplayName)->toBe(['en' => 'Test Gateway', 'ru' => 'Тестовый шлюз'])
        ->and($capabilities->logoPath)->toBe('/images/connectors/test.svg')
        ->and($capabilities->directMethods)->toHaveKey('card')
        ->and($capabilities->fallbackSessionType)->toBe(SessionResultType::ServerRedirect)
        ->and($capabilities->amountUnit)->toBe(AmountUnit::MinorUnits)
        ->and($capabilities->sbpMethod)->toBeNull();
});

test('ConnectorCapabilities defaults amountUnit to MinorUnits', function () {
    $capabilities = new ConnectorCapabilities(
        defaultDisplayName: ['en' => 'Stripe'],
        logoPath: '/images/connectors/stripe.svg',
        directMethods: [],
        fallbackSessionType: SessionResultType::ServerRedirect,
    );

    expect($capabilities->amountUnit)->toBe(AmountUnit::MinorUnits);
});

test('supportsDirectMethod returns true for registered methods', function () {
    $capabilities = new ConnectorCapabilities(
        defaultDisplayName: ['en' => 'Test'],
        logoPath: '/images/test.svg',
        directMethods: [
            'card' => new DirectMethod(SessionResultType::ServerRedirect),
            'apple_pay' => new DirectMethod(SessionResultType::EmbeddedWidget),
        ],
        fallbackSessionType: SessionResultType::ServerRedirect,
    );

    expect($capabilities->supportsDirectMethod('card'))->toBeTrue()
        ->and($capabilities->supportsDirectMethod('apple_pay'))->toBeTrue()
        ->and($capabilities->supportsDirectMethod('unknown'))->toBeFalse();
});

test('supportsDirectMethod returns true for sbp when sbpMethod is set', function () {
    $capabilities = new ConnectorCapabilities(
        defaultDisplayName: ['en' => 'Test'],
        logoPath: '/images/test.svg',
        directMethods: [],
        fallbackSessionType: SessionResultType::ServerRedirect,
        sbpMethod: new DirectMethod(SessionResultType::QrInline),
    );

    expect($capabilities->supportsDirectMethod('sbp'))->toBeTrue();
});

test('supportsDirectMethod returns false for sbp when sbpMethod is null and not in directMethods', function () {
    $capabilities = new ConnectorCapabilities(
        defaultDisplayName: ['en' => 'Test'],
        logoPath: '/images/test.svg',
        directMethods: [],
        fallbackSessionType: SessionResultType::ServerRedirect,
    );

    expect($capabilities->supportsDirectMethod('sbp'))->toBeFalse();
});

test('getDirectMethod returns method from directMethods', function () {
    $cardMethod = new DirectMethod(SessionResultType::ServerRedirect);

    $capabilities = new ConnectorCapabilities(
        defaultDisplayName: ['en' => 'Test'],
        logoPath: '/images/test.svg',
        directMethods: ['card' => $cardMethod],
        fallbackSessionType: SessionResultType::ServerRedirect,
    );

    expect($capabilities->getDirectMethod('card'))->toBe($cardMethod)
        ->and($capabilities->getDirectMethod('unknown'))->toBeNull();
});

test('getDirectMethod returns sbpMethod for sbp key when set', function () {
    $sbpMethod = new DirectMethod(SessionResultType::QrInline);

    $capabilities = new ConnectorCapabilities(
        defaultDisplayName: ['en' => 'Test'],
        logoPath: '/images/test.svg',
        directMethods: [
            'sbp' => new DirectMethod(SessionResultType::ServerRedirect),
        ],
        fallbackSessionType: SessionResultType::ServerRedirect,
        sbpMethod: $sbpMethod,
    );

    // sbpMethod takes priority over directMethods['sbp']
    expect($capabilities->getDirectMethod('sbp'))->toBe($sbpMethod);
});

test('displayName returns localized name', function () {
    $capabilities = new ConnectorCapabilities(
        defaultDisplayName: ['en' => 'Stripe', 'ru' => 'Страйп'],
        logoPath: '/images/stripe.svg',
        directMethods: [],
        fallbackSessionType: SessionResultType::ServerRedirect,
    );

    expect($capabilities->displayName('ru'))->toBe('Страйп')
        ->and($capabilities->displayName('en'))->toBe('Stripe');
});

test('displayName falls back to en when locale not found', function () {
    $capabilities = new ConnectorCapabilities(
        defaultDisplayName: ['en' => 'Stripe'],
        logoPath: '/images/stripe.svg',
        directMethods: [],
        fallbackSessionType: SessionResultType::ServerRedirect,
    );

    expect($capabilities->displayName('fr'))->toBe('Stripe');
});

test('displayName falls back to first value when en not found', function () {
    $capabilities = new ConnectorCapabilities(
        defaultDisplayName: ['ru' => 'Страйп'],
        logoPath: '/images/stripe.svg',
        directMethods: [],
        fallbackSessionType: SessionResultType::ServerRedirect,
    );

    expect($capabilities->displayName('fr'))->toBe('Страйп');
});

test('SessionResultType enum has correct values', function () {
    expect(SessionResultType::ServerRedirect->value)->toBe('redirect')
        ->and(SessionResultType::FormRedirect->value)->toBe('form_redirect')
        ->and(SessionResultType::EmbeddedWidget->value)->toBe('widget')
        ->and(SessionResultType::QrInline->value)->toBe('qr');
});

test('AmountUnit enum has correct values', function () {
    expect(AmountUnit::Rubles->value)->toBe('rubles')
        ->and(AmountUnit::Kopecks->value)->toBe('kopecks')
        ->and(AmountUnit::MinorUnits->value)->toBe('minor');
});

test('DirectMethod is a readonly value object', function () {
    $method = new DirectMethod(SessionResultType::ServerRedirect);

    expect($method->sessionType)->toBe(SessionResultType::ServerRedirect);
});
