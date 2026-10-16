<?php

declare(strict_types=1);

namespace App\Tests\Integration\Handler;

use App\Enum\EventStatus;
use App\Exception\DuplicateEventException;
use App\Factory\ProcessedEventFactory;
use App\Factory\RecordEventRequestFactory;
use App\Handler\EventProcessor;
use App\Model\RecordEventRequest;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The transport is sync:// in tests, so dispatching runs the handler inline and one
 * process() call ends with the pair already in the ledger.
 */
final class EventProcessorTest extends KernelTestCase
{
    private Connection $connection;
    private EventProcessor $processor;
    private RecordEventRequestFactory $requests;

    protected function setUp(): void
    {
        $container = self::getContainer();

        $this->connection = $container->get(Connection::class);

        // built by hand: nothing injects them until the controller arrives, so the
        // compiler drops them from the container
        $this->processor = new EventProcessor(
            $container->get(EntityManagerInterface::class),
            $container->get(MessageBusInterface::class),
            new ProcessedEventFactory(),
        );
        $this->requests = new RecordEventRequestFactory($container->get(ValidatorInterface::class));
    }

    public function testItStoresTheEventAndPostsTheLedgerPair(): void
    {
        $this->processor->process($this->request('evt_processor_1'));

        $event = $this->connection->fetchAssociative(
            'SELECT type, amount, currency, status FROM processed_events WHERE event_id = ?',
            ['evt_processor_1'],
        );

        self::assertNotFalse($event);
        self::assertSame('payment_received', $event['type']);
        self::assertSame('10050', (string) $event['amount']);
        self::assertSame('EUR', $event['currency']);
        self::assertSame(EventStatus::Posted->value, $event['status']);

        self::assertSame(2, $this->countTransactions('evt_processor_1'));
    }

    public function testTheSameEventIdIsRejectedAndPostsNothingTwice(): void
    {
        $this->processor->process($this->request('evt_processor_2'));

        $this->expectException(DuplicateEventException::class);

        try {
            $this->processor->process($this->request('evt_processor_2'));
        } finally {
            // still one event and one pair, not two
            self::assertSame(1, (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM processed_events WHERE event_id = ?',
                ['evt_processor_2'],
            ));
            self::assertSame(2, $this->countTransactions('evt_processor_2'));
        }
    }

    private function request(string $eventId): RecordEventRequest
    {
        return $this->requests->create(json_encode([
            'event_id' => $eventId,
            'type' => 'payment_received',
            'amount' => '100.50',
            'currency' => 'EUR',
            'timestamp' => '2026-01-01T00:00:00Z',
        ], \JSON_THROW_ON_ERROR));
    }

    private function countTransactions(string $eventId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM transactions WHERE event_id = ?',
            [$eventId],
        );
    }
}
