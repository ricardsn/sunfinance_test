<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AccountingTransaction;
use App\Enum\AccountName;
use App\Factory\PaginationFactory;
use App\Helper\MoneyFormatter;
use App\Repository\AccountingTransactionRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class AccountTransactionsController
{
    public function __construct(
        private AccountingTransactionRepository $transactions,
        private PaginationFactory $pagination,
        private MoneyFormatter $money,
    ) {
    }

    #[Route('/accounts/{id}/transactions', name: 'account_transactions', methods: ['GET'])]
    public function transactions(string $id, Request $request): JsonResponse
    {
        $account = AccountName::tryFrom($id)
            ?? throw new NotFoundHttpException(sprintf('Unknown account "%s".', $id));

        // read through all(), so a repeated parameter is a 422 from here and not a 400
        // from HttpFoundation refusing to hand back an array
        $query = $request->query->all();

        $pagination = $this->pagination->create($query['limit'] ?? null, $query['offset'] ?? null);

        $rows = $this->transactions->findLatestForAccount($account, $pagination->limit, $pagination->offset);

        return new JsonResponse([
            'account' => $account->value,
            'limit' => $pagination->limit,
            'offset' => $pagination->offset,
            'transactions' => array_map($this->present(...), $rows),
        ]);
    }

    /** @return array<string, string|int|null> */
    private function present(AccountingTransaction $transaction): array
    {
        return [
            'id' => $transaction->getId(),
            'event_id' => $transaction->getEventId(),
            'amount' => $this->money->toDecimal($transaction->getAmount(), $transaction->getCurrency()),
            'currency' => $transaction->getCurrency(),
            'direction' => $transaction->getDirection()->value,
            'created_at' => $transaction->getCreatedAt()->format(\DATE_ATOM),
        ];
    }
}
