<?php

declare(strict_types=1);

namespace App\Tests\Unit\Factory;

use App\Entity\ProcessedEvent;
use App\Enum\AccountName;
use App\Enum\Direction;
use App\Enum\EventType;
use App\Factory\AccountingTransactionFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AccountingTransactionFactoryTest extends TestCase
{
    #[DataProvider('pairs')]
    public function testItBuildsTheDebitAndCreditPair(
        EventType $type,
        AccountName $debit,
        AccountName $credit,
    ): void {
        $at = new \DateTimeImmutable('2026-01-02T03:04:05+00:00');

        $pair = (new AccountingTransactionFactory())->createPair($this->event($type), $at);

        self::assertCount(2, $pair);

        self::assertSame($debit, $pair[0]->getAccount());
        self::assertSame(Direction::Debit, $pair[0]->getDirection());

        self::assertSame($credit, $pair[1]->getAccount());
        self::assertSame(Direction::Credit, $pair[1]->getDirection());

        foreach ($pair as $transaction) {
            self::assertSame('evt_1', $transaction->getEventId());
            self::assertSame(10_050, $transaction->getAmount());
            self::assertSame('EUR', $transaction->getCurrency());
            self::assertSame($at, $transaction->getCreatedAt());
        }
    }

    /** @return iterable<string, array{EventType, AccountName, AccountName}> */
    public static function pairs(): iterable
    {
        yield 'payment_received: debit user, credit system cash' => [
            EventType::PaymentReceived,
            AccountName::UserAccount,
            AccountName::SystemCashAccount,
        ];

        yield 'payment_sent: debit system cash, credit user' => [
            EventType::PaymentSent,
            AccountName::SystemCashAccount,
            AccountName::UserAccount,
        ];

        yield 'fee_charged: debit user, credit fee' => [
            EventType::FeeCharged,
            AccountName::UserAccount,
            AccountName::FeeAccount,
        ];
    }

    public function testEveryEventTypeHasAPair(): void
    {
        $factory = new AccountingTransactionFactory();

        foreach (EventType::cases() as $type) {
            self::assertCount(2, $factory->createPair($this->event($type), new \DateTimeImmutable()), $type->value.' has no pair');
        }
    }

    private function event(EventType $type): ProcessedEvent
    {
        return new ProcessedEvent(
            'evt_1',
            $type,
            10_050,
            'EUR',
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );
    }
}
