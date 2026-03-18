<?php

declare(strict_types=1);

namespace App\Contracts\Enums;

interface HasColor
{
    public function getColor(): string;
}
