<?php

declare(strict_types=1);

namespace App\Tests\Integration\Messenger;

use App\Message\ProcessFinancialEvent;
use App\Tests\Fixture\FailingMessage;
use App\Tests\Fixture\FailingMessageHandler;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * What happens to a message that keeps failing. These run against the real Postgres
 * queue (the `queue_test` transport, test env only) rather than sync://, because the
 * retry and dead-letter handling only exists in the worker.
 */
final class RetryAndFailureTest extends KernelTestCase
{
    private Connection $connection;
    private MessageBusInterface $bus;

    protected function setUp(): void
    {
        $container = self::getContainer();

        $this->connection = $container->get(Connection::class);
        $this->bus = $container->get(MessageBusInterface::class);
    }

    public function testAFailingMessageIsRetriedThreeTimesAndThenDeadLettered(): void
    {
        $handler = self::getContainer()->get(FailingMessageHandler::class);

        $this->dispatch(new FailingMessage('retry_me'));
        $this->consume(4);

        // the first delivery plus the 3 retries configured on the transport
        self::assertSame(4, $handler->calls);
        self::assertSame(0, $this->queued('queue_test'));
        self::assertSame(1, $this->queued('failed'));
    }

    public function testAnUnrecoverableMessageIsDeadLetteredWithoutRetrying(): void
    {
        // the handler throws UnrecoverableMessageHandlingException for an event that is
        // not stored, so retrying is skipped
        $this->dispatch(new ProcessFinancialEvent('evt_never_stored'));
        $this->consume(1);

        // nothing came back for a second attempt: one delivery, then the dead letter
        self::assertSame(0, $this->queued('queue_test'));
        self::assertSame(1, $this->queued('failed'));
    }

    private function dispatch(object $message): void
    {
        $this->bus->dispatch($message, [new TransportNamesStamp('queue_test')]);
    }

    private function consume(int $messages): void
    {
        $application = new Application(self::$kernel);
        $application->setAutoExit(false);

        $tester = new CommandTester($application->find('messenger:consume'));
        $tester->execute([
            'receivers' => ['queue_test'],
            '--limit' => $messages,
            // a safety net: without it a queue that stops delivering would hang the suite
            '--time-limit' => 5,
        ]);
    }

    private function queued(string $queue): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = ?',
            [$queue],
        );
    }
}
