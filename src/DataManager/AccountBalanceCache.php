<?php

declare(strict_types=1);

namespace App\DataManager;

use App\Enum\AccountName;
use App\Factory\AccountBalanceFactory;
use App\Model\AccountBalance;
use App\Repository\AccountingTransactionRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Read-through cache over the balance sums, which are the one query whose cost grows
 * with the size of the ledger. A Redis failure is not an error here: Symfony's adapter
 * logs it and reports a miss, so the read falls through to Postgres.
 */
final readonly class AccountBalanceCache
{
    /**
     * Only a safety net. Posting clears the entry, so this just means that an
     * invalidation lost to a Redis outage cannot serve a stale balance forever.
     */
    private const STALE_AFTER = 3600;

    public function __construct(
        #[Autowire(service: 'cache.balance')]
        private CacheInterface $cache,
        private AccountingTransactionRepository $transactions,
        private AccountBalanceFactory $balances,
    ) {
    }

    /** @return list<AccountBalance> */
    public function forAccount(AccountName $account): array
    {
        /** @var list<AccountBalance> $balances */
        $balances = $this->cache->get($this->key($account), function (ItemInterface $item) use ($account): array {
            $item->expiresAfter(self::STALE_AFTER);

            return $this->balances->createList($this->transactions->sumByCurrency($account));
        });

        return $balances;
    }

    public function invalidate(AccountName ...$accounts): void
    {
        foreach ($accounts as $account) {
            $this->cache->delete($this->key($account));
        }
    }

    private function key(AccountName $account): string
    {
        return 'balance.'.$account->value;
    }
}
