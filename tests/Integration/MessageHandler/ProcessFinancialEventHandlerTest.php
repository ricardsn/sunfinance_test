<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\ProcessedEvent;
use App\Enum\AccountName;
use App\Enum\Direction;
use App\Enum\EventStatus;
use App\Enum\EventType;
use App\Message\ProcessFinancialEvent;
use App\MessageHandler\ProcessFinancialEventHandler;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class ProcessFinancialEventHandlerTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private ProcessFinancialEventHandler $handler;

    protected function setUp(): void
    {
        $container = self::getContainer();

        $this->connection = $container->get(Connection::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->handler = $container->get(ProcessFinancialEventHandler::class);
    }

    /** @param list<array{AccountName, Direction}> $expected */
    #[DataProvider('eventTypes')]
    public function testItPostsThePairForEachEventType(EventType $type, array $expected): void
    {
        $eventId = 'evt_handler_'.$type->value;
        $this->storePendingEvent($eventId, $type);

        ($this->handler)(new ProcessFinancialEvent($eventId));

        $rows = $this->connection->fetchAllAssociative(
            'SELECT account, amount, currency, direction FROM transactions WHERE event_id = ? ORDER BY id',
            [$eventId],
        );

        self::assertCount(2, $rows);

        foreach ($expected as $i => [$account, $direction]) {
            self::assertSame($account->value, $rows[$i]['account']);
            self::assertSame($direction->value, $rows[$i]['direction']);
            self::assertSame('10050', (string) $rows[$i]['amount']);
            self::assertSame('EUR', $rows[$i]['currency']);
        }

        self::assertSame(EventStatus::Posted->value, $this->connection->fetchOne(
            'SELECT status FROM processed_events WHERE event_id = ?',
            [$eventId],
        ));
    }

    /** @return iterable<string, array{EventType, list<array{AccountName, Direction}>}> */
    public static function eventTypes(): iterable
    {
        yield 'payment_received' => [EventType::PaymentReceived, [
            [AccountName::UserAccount, Direction::Debit],
            [AccountName::SystemCashAccount, Direction::Credit],
        ]];

        yield 'payment_sent' => [EventType::PaymentSent, [
            [AccountName::SystemCashAccount, Direction::Debit],
            [AccountName::UserAccount, Direction::Credit],
        ]];

        yield 'fee_charged' => [EventType::FeeCharged, [
            [AccountName::UserAccount, Direction::Debit],
            [AccountName::FeeAccount, Direction::Credit],
        ]];
    }

    public function testHandlingTheSameMessageTwiceLeavesOnlyOnePair(): void
    {
        $this->storePendingEvent('evt_handler_replay', EventType::FeeCharged);

        ($this->handler)(new ProcessFinancialEvent('evt_handler_replay'));
        ($this->handler)(new ProcessFinancialEvent('evt_handler_replay'));

        self::assertSame(2, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM transactions WHERE event_id = ?',
            ['evt_handler_replay'],
        ));
    }

    public function testAMessageForAnUnknownEventIsNotRetried(): void
    {
        $this->expectException(UnrecoverableMessageHandlingException::class);

        ($this->handler)(new ProcessFinancialEvent('evt_handler_missing'));
    }

    private function storePendingEvent(string $eventId, EventType $type): void
    {
        $this->entityManager->persist(new ProcessedEvent(
            $eventId,
            $type,
            10_050,
            'EUR',
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        ));
        $this->entityManager->flush();
    }
}
