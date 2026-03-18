<?php

declare(strict_types=1);

use Streeboga\PaymentData\Enums\PaymentStatus;

test('all 12 payment statuses exist', function () {
    expect(PaymentStatus::cases())->toHaveCount(12);
});

test('terminal statuses are correctly identified', function () {
    expect(PaymentStatus::Succeeded->isTerminal())->toBeTrue();
    expect(PaymentStatus::Failed->isTerminal())->toBeTrue();
    expect(PaymentStatus::Cancelled->isTerminal())->toBeTrue();
    expect(PaymentStatus::Expired->isTerminal())->toBeTrue();
    expect(PaymentStatus::PartiallyCaptured->isTerminal())->toBeTrue();
});

test('non-terminal statuses are correctly identified', function () {
    expect(PaymentStatus::RequiresPaymentMethod->isTerminal())->toBeFalse();
    expect(PaymentStatus::RequiresConfirmation->isTerminal())->toBeFalse();
    expect(PaymentStatus::Processing->isTerminal())->toBeFalse();
    expect(PaymentStatus::RequiresCapture->isTerminal())->toBeFalse();
    expect(PaymentStatus::RequiresCustomerAction->isTerminal())->toBeFalse();
});

test('status values are snake_case strings', function () {
    foreach (PaymentStatus::cases() as $status) {
        expect($status->value)->toMatch('/^[a-z_]+$/');
    }
});

test('canTransitionTo delegates to StateMachine', function () {
    expect(PaymentStatus::Processing->canTransitionTo(PaymentStatus::Succeeded))->toBeTrue();
    expect(PaymentStatus::Succeeded->canTransitionTo(PaymentStatus::Cancelled))->toBeFalse();
});

test('getLabel returns non-empty string for all statuses', function () {
    foreach (PaymentStatus::cases() as $status) {
        expect($status->getLabel())->toBeString()->not->toBeEmpty();
    }
});

test('getColor returns valid color string for all statuses', function () {
    $validColors = ['success', 'danger', 'warning', 'info', 'gray'];

    foreach (PaymentStatus::cases() as $status) {
        expect($validColors)->toContain($status->getColor());
    }
});
