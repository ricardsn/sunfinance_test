<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AccountingTransaction;
use App\Enum\AccountName;
use App\Enum\Direction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AccountingTransaction> */
final class AccountingTransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountingTransaction::class);
    }

    /**
     * One row per currency the account has been posted in, debits and credits summed
     * separately. Postgres returns SUM() over a BIGINT column as a string.
     *
     * @return list<array{currency: string, debit: string, credit: string}>
     */
    public function sumByCurrency(AccountName $account): array
    {
        /** @var list<array{currency: string, debit: string, credit: string}> $sums */
        $sums = $this->createQueryBuilder('t')
            ->select('t.currency AS currency')
            ->addSelect('SUM(CASE WHEN t.direction = :debit THEN t.amount ELSE 0 END) AS debit')
            ->addSelect('SUM(CASE WHEN t.direction = :credit THEN t.amount ELSE 0 END) AS credit')
            ->where('t.account = :account')
            ->setParameter('account', $account)
            ->setParameter('debit', Direction::Debit)
            ->setParameter('credit', Direction::Credit)
            ->groupBy('t.currency')
            ->orderBy('t.currency', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return $sums;
    }

    /**
     * Newest first. The ledger is insert-only, so the id order is the posting order.
     *
     * @return list<AccountingTransaction>
     */
    public function findLatestForAccount(AccountName $account, int $limit, int $offset): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.account = :account')
            ->setParameter('account', $account)
            ->orderBy('t.id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }
}
