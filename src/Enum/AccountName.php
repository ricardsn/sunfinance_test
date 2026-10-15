<?php

declare(strict_types=1);

namespace App\Enum;

enum AccountName: string
{
    case UserAccount = 'user_account';
    case SystemCashAccount = 'system_cash_account';
    case FeeAccount = 'fee_account';
}
