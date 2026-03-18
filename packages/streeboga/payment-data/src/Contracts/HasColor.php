<?php

declare(strict_types=1);

namespace Streeboga\PaymentData\Contracts;

interface HasColor
{
    public function getColor(): string;
}
