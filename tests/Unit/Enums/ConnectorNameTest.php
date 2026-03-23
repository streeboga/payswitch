<?php

declare(strict_types=1);

use App\Enums\ConnectorName;

test('all 9 connector names exist', function () {
    expect(ConnectorName::cases())->toHaveCount(9);
});

test('YooKassa case exists', function () {
    expect(ConnectorName::YooKassa->value)->toBe('yookassa');
});

test('all cases have labels', function () {
    foreach (ConnectorName::cases() as $case) {
        expect($case->getLabel())->toBeString()->not->toBeEmpty();
    }
});

test('all cases have colors', function () {
    foreach (ConnectorName::cases() as $case) {
        expect($case->getColor())->toBeString()->not->toBeEmpty();
    }
});

test('all cases have icons', function () {
    foreach (ConnectorName::cases() as $case) {
        expect($case->getIcon())->toBeString()->not->toBeEmpty();
    }
});

test('values returns all string values', function () {
    expect(ConnectorName::values())->toBe(['stripe', 'cloudpayments', 'yookassa', 'sberbank', 'alfabank', 'tbank', 'robokassa', 'tochka', 'test']);
});

test('options returns label map', function () {
    $options = ConnectorName::options();

    expect($options)->toHaveKey('yookassa')
        ->and($options['yookassa'])->toBe('ЮKassa');
});
