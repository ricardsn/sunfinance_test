<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\AccountingTransaction;
use App\Entity\ProcessedEvent;
use App\Enum\AccountName;
use App\Enum\Direction;

/** Builds the double-entry pair for an event: same amount, one debit and one credit. */
final readonly class AccountingTransactionFactory
{
    /** @return list<AccountingTransaction> */
    public function createPair(ProcessedEvent $event, \DateTimeImmutable $at): array
    {
        [$debit, $credit] = $event->getType()->accounts();

        return [
            $this->create($event, $debit, Direction::Debit, $at),
            $this->create($event, $credit, Direction::Credit, $at),
        ];
    }

    private function create(
        ProcessedEvent $event,
        AccountName $account,
        Direction $direction,
        \DateTimeImmutable $at,
    ): AccountingTransaction {
        return new AccountingTransaction(
            $event->getEventId(),
            $account,
            $event->getAmount(),
            $event->getCurrency(),
            $direction,
            $at,
        );
    }
}
