<?php

declare(strict_types=1);

namespace Streeboga\PaymentData\Enums;

enum WebhookEventType: string
{
    case PaymentSucceeded = 'payment_succeeded';
    case PaymentFailed = 'payment_failed';
    case PaymentProcessing = 'payment_processing';
    case PaymentCancelled = 'payment_cancelled';
    case PaymentAuthorized = 'payment_authorized';
    case PaymentCaptured = 'payment_captured';
    case ActionRequired = 'action_required';
    case RefundSucceeded = 'refund_succeeded';
    case RefundFailed = 'refund_failed';
}
