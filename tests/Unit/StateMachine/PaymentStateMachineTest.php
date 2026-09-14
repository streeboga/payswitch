<?php

declare(strict_types=1);

use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Exceptions\InvalidStateTransitionException;
use Streeboga\PaymentData\StateMachine\PaymentStateMachine;

// --- Valid transitions ---

test('requires_payment_method → requires_confirmation is valid', function () {
    expect(PaymentStateMachine::canTransition(
        PaymentStatus::RequiresPaymentMethod,
        PaymentStatus::RequiresConfirmation
    ))->toBeTrue();
});

test('requires_payment_method → processing is valid', function () {
    expect(PaymentStateMachine::canTransition(
        PaymentStatus::RequiresPaymentMethod,
        PaymentStatus::Processing
    ))->toBeTrue();
});

test('requires_payment_method → cancelled is valid', function () {
    expect(PaymentStateMachine::canTransition(
        PaymentStatus::RequiresPaymentMethod,
        PaymentStatus::Cancelled
    ))->toBeTrue();
});

test('requires_payment_method → expired is valid', function () {
    expect(PaymentStateMachine::canTransition(
        PaymentStatus::RequiresPaymentMethod,
        PaymentStatus::Expired
    ))->toBeTrue();
});

test('requires_confirmation → processing is valid', function () {
    expect(PaymentStateMachine::canTransition(
        PaymentStatus::RequiresConfirmation,
        PaymentStatus::Processing
    ))->toBeTrue();
});

test('requires_confirmation → requires_capture is valid (manual capture)', function () {
    expect(PaymentStateMachine::canTransition(
        PaymentStatus::RequiresConfirmation,
        PaymentStatus::RequiresCapture
    ))->toBeTrue();
});

test('requires_confirmation → requires_customer_action is valid (3DS)', function () {
    expect(PaymentStateMachine::canTransition(
        PaymentStatus::RequiresConfirmation,
        PaymentStatus::RequiresCustomerAction
    ))->toBeTrue();
});

test('requires_confirmation → failed is valid', function () {
    expect(PaymentStateMachine::canTransition(
        PaymentStatus::RequiresConfirmation,
        PaymentStatus::Failed
    ))->toBeTrue();
});

test('processing → succeeded is valid', function () {
    expect(PaymentStateMachine::canTransition(
        PaymentStatus::Processing,
        PaymentStatus::Succeeded
    ))->toBeTrue();
});

test('processing → failed is valid', function () {
    expect(PaymentStateMachine::canTransition(
        PaymentStatus::Processing,
        PaymentStatus::Failed
    ))->toBeTrue();
});

test('processing → requires_capture is valid', function () {
    expect(PaymentStateMachine::canTransition(
        PaymentStatus::Processing,
        PaymentStatus::RequiresCapture
    ))->toBeTrue();
});

test('requires_capture → succeeded is valid (capture)', function () {
    expect(PaymentStateMachine::canTransition(
        PaymentStatus::RequiresCapture,
        PaymentStatus::Succeeded
    ))->toBeTrue();
});

test('requires_capture → cancelled is valid', function () {
    expect(PaymentStateMachine::canTransition(
        PaymentStatus::RequiresCapture,
        PaymentStatus::Cancelled
    ))->toBeTrue();
});

test('requires_customer_action → processing is valid', function () {
    expect(PaymentStateMachine::canTransition(
        PaymentStatus::RequiresCustomerAction,
        PaymentStatus::Processing
    ))->toBeTrue();
});

// --- Invalid transitions ---

test('succeeded → cancelled is invalid', function () {
    expect(PaymentStateMachine::canTransition(
        PaymentStatus::Succeeded,
        PaymentStatus::Cancelled
    ))->toBeFalse();
});

test('succeeded → processing is invalid', function () {
    expect(PaymentStateMachine::canTransition(
        PaymentStatus::Succeeded,
        PaymentStatus::Processing
    ))->toBeFalse();
});

test('failed → succeeded is invalid', function () {
    expect(PaymentStateMachine::canTransition(
        PaymentStatus::Failed,
        PaymentStatus::Succeeded
    ))->toBeFalse();
});

test('cancelled → processing is invalid', function () {
    expect(PaymentStateMachine::canTransition(
        PaymentStatus::Cancelled,
        PaymentStatus::Processing
    ))->toBeFalse();
});

test('expired → any is invalid', function () {
    foreach (PaymentStatus::cases() as $target) {
        if ($target === PaymentStatus::Expired) {
            continue;
        }
        expect(PaymentStateMachine::canTransition(PaymentStatus::Expired, $target))->toBeFalse();
    }
});

// --- Terminal statuses ---

test('terminal statuses have no outgoing transitions', function () {
    $terminals = [
        PaymentStatus::Succeeded,
        PaymentStatus::Failed,
        PaymentStatus::Cancelled,
        PaymentStatus::Expired,
        PaymentStatus::PartiallyCaptured,
    ];

    foreach ($terminals as $status) {
        expect(PaymentStateMachine::allowedTransitions($status))->toBeEmpty();
    }
});

// --- Подтверждение провайдером ---

test('provider confirmation brings an expired or failed payment to success', function (PaymentStatus $from) {
    expect(PaymentStateMachine::canConfirmByProvider($from, PaymentStatus::Succeeded))->toBeTrue()
        ->and(PaymentStateMachine::canConfirmByProvider($from, PaymentStatus::RequiresCapture))->toBeTrue()
        ->and(PaymentStateMachine::canConfirmByProvider($from, PaymentStatus::RequiresMerchantAction))->toBeTrue()
        // Общая таблица при этом не расширилась.
        ->and(PaymentStateMachine::canTransition($from, PaymentStatus::Succeeded))->toBeFalse();
})->with([PaymentStatus::Expired, PaymentStatus::Failed]);

test('provider confirmation of a cancelled payment only asks the merchant', function () {
    expect(PaymentStateMachine::canConfirmByProvider(PaymentStatus::Cancelled, PaymentStatus::Succeeded))->toBeFalse()
        ->and(PaymentStateMachine::canConfirmByProvider(PaymentStatus::Cancelled, PaymentStatus::RequiresMerchantAction))->toBeTrue()
        ->and(PaymentStateMachine::canConfirmByProvider(PaymentStatus::Succeeded, PaymentStatus::Succeeded))->toBeFalse();
});

test('requires_merchant_action is reachable from the open statuses', function (PaymentStatus $from) {
    expect(PaymentStateMachine::canTransition($from, PaymentStatus::RequiresMerchantAction))->toBeTrue();
})->with([
    PaymentStatus::RequiresPaymentMethod,
    PaymentStatus::RequiresConfirmation,
    PaymentStatus::RequiresCustomerAction,
    PaymentStatus::Processing,
]);

// --- assertTransition ---

test('assertTransition passes on valid transition', function () {
    PaymentStateMachine::assertTransition(
        PaymentStatus::Processing,
        PaymentStatus::Succeeded
    );

    expect(true)->toBeTrue();
});

test('assertTransition throws InvalidStateTransitionException on invalid', function () {
    PaymentStateMachine::assertTransition(
        PaymentStatus::Succeeded,
        PaymentStatus::Cancelled
    );
})->throws(InvalidStateTransitionException::class);

// --- allowedTransitions ---

test('allowedTransitions returns correct statuses for requires_payment_method', function () {
    $allowed = PaymentStateMachine::allowedTransitions(PaymentStatus::RequiresPaymentMethod);

    expect($allowed)
        ->toContain(PaymentStatus::RequiresConfirmation)
        ->toContain(PaymentStatus::Processing)
        ->toContain(PaymentStatus::Cancelled)
        ->toContain(PaymentStatus::Expired);
});

test('allowedTransitions returns correct statuses for requires_capture', function () {
    $allowed = PaymentStateMachine::allowedTransitions(PaymentStatus::RequiresCapture);

    expect($allowed)
        ->toContain(PaymentStatus::Succeeded)
        ->toContain(PaymentStatus::Cancelled)
        ->not->toContain(PaymentStatus::Processing);
});
