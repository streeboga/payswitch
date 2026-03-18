<?php

declare(strict_types=1);

namespace App\Enums;

enum WebhookEventType: string
{
    case PaymentSucceeded = 'payment_succeeded';
    case PaymentCaptured = 'payment_captured';
    case PaymentCancelled = 'payment_cancelled';
    case PaymentAuthorized = 'payment_authorized';
    case PaymentStatusChanged = 'payment_status_changed';
    case RefundSucceeded = 'refund_succeeded';
    case RefundFailed = 'refund_failed';
}
