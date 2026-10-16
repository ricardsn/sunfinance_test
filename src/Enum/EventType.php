<?php

declare(strict_types=1);

namespace App\Enum;

enum EventType: string
{
    case PaymentReceived = 'payment_received';
    case PaymentSent = 'payment_sent';
    case FeeCharged = 'fee_charged';

    /**
     * The double-entry pair for this event type. A new type means a new case and a new
     * arm here - the match has no default, so a missing arm is a static analysis error.
     *
     * @return array{AccountName, AccountName} the debited and the credited account
     */
    public function accounts(): array
    {
        return match ($this) {
            self::PaymentReceived => [AccountName::UserAccount, AccountName::SystemCashAccount],
            self::PaymentSent => [AccountName::SystemCashAccount, AccountName::UserAccount],
            self::FeeCharged => [AccountName::UserAccount, AccountName::FeeAccount],
        };
    }
}
