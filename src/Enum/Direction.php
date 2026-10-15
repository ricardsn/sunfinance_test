<?php

declare(strict_types=1);

namespace App\Enum;

enum Direction: string
{
    case Debit = 'debit';
    case Credit = 'credit';
}
