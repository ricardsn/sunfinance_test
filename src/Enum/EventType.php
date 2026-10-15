<?php

declare(strict_types=1);

namespace App\Enum;

enum EventType: string
{
    case PaymentReceived = 'payment_received';
    case PaymentSent = 'payment_sent';
    case FeeCharged = 'fee_charged';
}
