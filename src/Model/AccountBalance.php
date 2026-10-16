<?php

declare(strict_types=1);

namespace App\Model;

/** One currency's totals for one account, in minor units. */
final class AccountBalance
{
    public function __construct(
        public readonly string $currency,
        public readonly int $debitTotal,
        public readonly int $creditTotal,
    ) {
    }

    /** Debit minus credit, so the sign says which side the account is on. */
    public function balance(): int
    {
        return $this->debitTotal - $this->creditTotal;
    }
}
