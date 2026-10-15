<?php

declare(strict_types=1);

namespace App\Enum;

enum EventStatus: string
{
    case Pending = 'pending';
    case Posted = 'posted';
}
