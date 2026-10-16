<?php

declare(strict_types=1);

namespace App\Tests\Integration\DataManager;

use App\DataManager\AccountBalanceCache;
use App\Enum\AccountName;
use App\Enum\Direction;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Writes rows straight to the ledger, behind the cache's back, so a stale read proves
 * the value came from the cache and a fresh one proves the invalidation worked.
 */
final class AccountBalanceCacheTest extends KernelTestCase
{
    private Connection $connection;
    private AccountBalanceCache $balances;

    protected function setUp(): void
    {
        $container = self::getContainer();

        $this->connection = $container->get(Connection::class);
        $this->balances = $container->get(AccountBalanceCache::class);
    }

    public function testASecondReadIsServedFromTheCache(): void
    {
        $this->insertTransaction(10050);

        self::assertSame(10050, $this->balances->forAccount(AccountName::UserAccount)[0]->debitTotal);

        $this->insertTransaction(5000);

        self::assertSame(10050, $this->balances->forAccount(AccountName::UserAccount)[0]->debitTotal);
    }

    public function testInvalidatingMakesTheNextReadHitTheLedger(): void
    {
        $this->insertTransaction(10050);
        $this->balances->forAccount(AccountName::UserAccount);

        $this->insertTransaction(5000);
        $this->balances->invalidate(AccountName::UserAccount);

        self::assertSame(15050, $this->balances->forAccount(AccountName::UserAccount)[0]->debitTotal);
    }

    public function testInvalidatingOneAccountLeavesTheOthersAlone(): void
    {
        $this->insertTransaction(10050);
        $this->insertTransaction(10050, AccountName::FeeAccount);

        $this->balances->forAccount(AccountName::UserAccount);
        $this->balances->forAccount(AccountName::FeeAccount);

        $this->insertTransaction(5000);
        $this->insertTransaction(5000, AccountName::FeeAccount);
        $this->balances->invalidate(AccountName::UserAccount);

        self::assertSame(15050, $this->balances->forAccount(AccountName::UserAccount)[0]->debitTotal);
        self::assertSame(10050, $this->balances->forAccount(AccountName::FeeAccount)[0]->debitTotal);
    }

    private function insertTransaction(int $amount, AccountName $account = AccountName::UserAccount): void
    {
        $this->connection->insert('transactions', [
            'event_id' => uniqid('evt_cache_', true),
            'account' => $account->value,
            'amount' => $amount,
            'currency' => 'EUR',
            'direction' => Direction::Debit->value,
            'created_at' => '2026-01-01 00:00:00',
        ]);
    }
}
