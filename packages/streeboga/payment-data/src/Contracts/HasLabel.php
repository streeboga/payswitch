<?php

declare(strict_types=1);

namespace Streeboga\PaymentData\Contracts;

interface HasLabel
{
    public function getLabel(): string;
}
