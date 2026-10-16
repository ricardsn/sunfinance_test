<?php

declare(strict_types=1);

namespace App\Model;

/** One currency's totals for one account, in minor units. */
final readonly class AccountBalance
{
    public function __construct(
        public string $currency,
        public int $debitTotal,
        public int $creditTotal,
    ) {
    }

    /** Debit minus credit, so the sign says which side the account is on. */
    public function balance(): int
    {
        return $this->debitTotal - $this->creditTotal;
    }
}
