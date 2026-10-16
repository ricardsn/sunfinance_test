<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\ApiTestCase;
use Symfony\Component\HttpFoundation\Response;

final class AccountBalanceControllerTest extends ApiTestCase
{
    public function testItSumsTheLedgerPerCurrency(): void
    {
        $this->postEvent('evt_bal_1', 'payment_received');               // debit  100.50 EUR
        $this->postEvent('evt_bal_2', 'payment_sent');                   // credit 100.50 EUR
        $this->postEvent('evt_bal_3', 'fee_charged');                    // debit  100.50 EUR
        $this->postEvent('evt_bal_4', 'payment_received', '100', 'JPY'); // debit  100 JPY

        self::assertSame([
            'account' => 'user_account',
            'balances' => [
                ['currency' => 'EUR', 'debit_total' => '201.00', 'credit_total' => '100.50', 'balance' => '100.50'],
                // JPY has no minor unit, so its amounts carry no decimals
                ['currency' => 'JPY', 'debit_total' => '100', 'credit_total' => '0', 'balance' => '100'],
            ],
        ], $this->getJson('/accounts/user_account/balance'));
    }

    public function testTheTwoSidesOfAPaymentCancelOut(): void
    {
        $this->postEvent('evt_bal_5', 'payment_received');
        $this->postEvent('evt_bal_6', 'payment_sent');

        self::assertSame(
            [['currency' => 'EUR', 'debit_total' => '100.50', 'credit_total' => '100.50', 'balance' => '0.00']],
            $this->getJson('/accounts/system_cash_account/balance')['balances'],
        );
    }

    public function testAnAccountThatIsOnlyCreditedHasANegativeBalance(): void
    {
        $this->postEvent('evt_bal_7', 'fee_charged');

        self::assertSame(
            [['currency' => 'EUR', 'debit_total' => '0.00', 'credit_total' => '100.50', 'balance' => '-100.50']],
            $this->getJson('/accounts/fee_account/balance')['balances'],
        );
    }

    public function testAnAccountWithNoTransactionsAnswersWithNoBalances(): void
    {
        self::assertSame(
            ['account' => 'fee_account', 'balances' => []],
            $this->getJson('/accounts/fee_account/balance'),
        );
    }

    /**
     * The balance is cached, so posting has to clear it - otherwise the second read
     * would still answer with the first total.
     */
    public function testANewEventIsVisibleInTheBalanceItAffects(): void
    {
        $this->postEvent('evt_bal_8', 'fee_charged');

        self::assertSame('-100.50', $this->getJson('/accounts/fee_account/balance')['balances'][0]['balance']);

        $this->postEvent('evt_bal_9', 'fee_charged');

        self::assertSame('-201.00', $this->getJson('/accounts/fee_account/balance')['balances'][0]['balance']);
    }

    public function testAnUnknownAccountAnswersWith404(): void
    {
        $problem = $this->getJson('/accounts/savings_account/balance', Response::HTTP_NOT_FOUND);

        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertSame(Response::HTTP_NOT_FOUND, $problem['status']);
    }
}
