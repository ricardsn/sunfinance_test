<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Model\Pagination;
use App\Tests\Functional\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

final class AccountTransactionsControllerTest extends ApiTestCase
{
    public function testItReturnsTheAccountsRowsNewestFirst(): void
    {
        $this->postEvent('evt_tx_1', 'payment_received'); // user debit
        $this->postEvent('evt_tx_2', 'payment_sent');     // user credit
        $this->postEvent('evt_tx_3', 'fee_charged');      // user debit

        $body = $this->getJson('/accounts/user_account/transactions');

        self::assertSame('user_account', $body['account']);
        self::assertSame(Pagination::DEFAULT_LIMIT, $body['limit']);
        self::assertSame(0, $body['offset']);
        self::assertSame(['evt_tx_3', 'evt_tx_2', 'evt_tx_1'], array_column($body['transactions'], 'event_id'));
        self::assertSame(['debit', 'credit', 'debit'], array_column($body['transactions'], 'direction'));
    }

    public function testARowCarriesTheWholeTransaction(): void
    {
        $this->postEvent('evt_tx_4', 'fee_charged');

        $row = $this->getJson('/accounts/fee_account/transactions')['transactions'][0];

        self::assertIsInt($row['id']);
        self::assertSame('evt_tx_4', $row['event_id']);
        self::assertSame('100.50', $row['amount']);
        self::assertSame('EUR', $row['currency']);
        self::assertSame('credit', $row['direction']);
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DATE_ATOM, $row['created_at']));
    }

    public function testLimitAndOffsetPageThroughTheRows(): void
    {
        $this->postEvent('evt_tx_5', 'payment_received');
        $this->postEvent('evt_tx_6', 'payment_sent');
        $this->postEvent('evt_tx_7', 'fee_charged');

        $page = $this->getJson('/accounts/user_account/transactions?limit=2');

        self::assertSame(2, $page['limit']);
        self::assertSame(['evt_tx_7', 'evt_tx_6'], array_column($page['transactions'], 'event_id'));

        $next = $this->getJson('/accounts/user_account/transactions?limit=2&offset=2');

        self::assertSame(2, $next['offset']);
        self::assertSame(['evt_tx_5'], array_column($next['transactions'], 'event_id'));

        self::assertSame([], $this->getJson('/accounts/user_account/transactions?offset=3')['transactions']);
    }

    #[DataProvider('invalidPagination')]
    public function testAnInvalidLimitOrOffsetAnswersWith422(string $query, string $expectedField): void
    {
        $problem = $this->getJson(
            '/accounts/user_account/transactions?'.$query,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );

        self::assertSame([$expectedField], array_keys($problem['errors']));
    }

    /** @return array<string, array{string, string}> */
    public static function invalidPagination(): array
    {
        return [
            'limit zero' => ['limit=0', 'limit'],
            'limit over the cap' => ['limit='.(Pagination::MAX_LIMIT + 1), 'limit'],
            'limit not a number' => ['limit=abc', 'limit'],
            'limit negative' => ['limit=-1', 'limit'],
            'limit repeated' => ['limit[]=1', 'limit'],
            'offset negative' => ['offset=-1', 'offset'],
            'offset not a number' => ['offset=abc', 'offset'],
        ];
    }

    public function testAnUnknownAccountAnswersWith404(): void
    {
        $problem = $this->getJson('/accounts/savings_account/transactions', Response::HTTP_NOT_FOUND);

        self::assertSame(Response::HTTP_NOT_FOUND, $problem['status']);
    }
}
