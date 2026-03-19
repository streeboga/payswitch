<?php

declare(strict_types=1);

use Streeboga\PaymentConnectors\Drivers\StripeConnector;

test('stripe purchase creates PaymentIntent not legacy charge', function () {
    // Omnipay uses deprecated Charges API, not PaymentIntents
    expect(true)->toBeTrue();
})->skip('BUG #4: StripeConnector uses Omnipay which targets deprecated Charges API, not PaymentIntents');

test('stripe purchase supports idempotency key', function () {
    // Omnipay doesn't pass Idempotency-Key header to Stripe
    expect(true)->toBeTrue();
})->skip('BUG #17: Omnipay does not support Stripe Idempotency-Key header');
