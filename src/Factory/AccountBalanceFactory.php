<?php

declare(strict_types=1);

namespace App\Factory;

use App\Model\AccountBalance;

/** Turns the repository's per-currency sums into balances. */
final readonly class AccountBalanceFactory
{
    /**
     * @param list<array{currency: string, debit: string, credit: string}> $sums
     *
     * @return list<AccountBalance>
     */
    public function createList(array $sums): array
    {
        return array_map(
            static fn (array $sum): AccountBalance => new AccountBalance(
                $sum['currency'],
                (int) $sum['debit'],
                (int) $sum['credit'],
            ),
            $sums,
        );
    }
}
