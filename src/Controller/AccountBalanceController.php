<?php

declare(strict_types=1);

namespace App\Controller;

use App\DataManager\AccountBalanceCache;
use App\Enum\AccountName;
use App\Helper\MoneyFormatter;
use App\Model\AccountBalance;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final class AccountBalanceController
{
    public function __construct(
        private readonly AccountBalanceCache $balances,
        private readonly MoneyFormatter $money,
    ) {
    }

    /**
     * Read through the cache, which the worker clears when it posts to the account. An
     * account that has never been posted to answers with an empty list, not a 404 -
     * only a name that is not an account is a 404.
     */
    #[Route('/accounts/{id}/balance', name: 'account_balance', methods: ['GET'])]
    public function balance(string $id): JsonResponse
    {
        $account = AccountName::tryFrom($id)
            ?? throw new NotFoundHttpException(sprintf('Unknown account "%s".', $id));

        return new JsonResponse([
            'account' => $account->value,
            'balances' => array_map($this->present(...), $this->balances->forAccount($account)),
        ]);
    }

    /** @return array<string, string> */
    private function present(AccountBalance $balance): array
    {
        return [
            'currency' => $balance->currency,
            'debit_total' => $this->money->toDecimal($balance->debitTotal, $balance->currency),
            'credit_total' => $this->money->toDecimal($balance->creditTotal, $balance->currency),
            'balance' => $this->money->toDecimal($balance->balance(), $balance->currency),
        ];
    }
}
