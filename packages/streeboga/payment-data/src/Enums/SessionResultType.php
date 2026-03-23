<?php

declare(strict_types=1);

namespace Streeboga\PaymentData\Enums;

enum SessionResultType: string
{
    case ServerRedirect = 'redirect';
    case FormRedirect = 'form_redirect';
    case EmbeddedWidget = 'widget';
    case QrInline = 'qr';
}
