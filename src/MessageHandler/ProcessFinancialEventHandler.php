<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Factory\AccountingTransactionFactory;
use App\Message\ProcessFinancialEvent;
use App\Repository\ProcessedEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/** Writes the ledger pair for a stored event. Safe to run more than once. */
#[AsMessageHandler]
final class ProcessFinancialEventHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProcessedEventRepository $events,
        private readonly AccountingTransactionFactory $transactions,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(ProcessFinancialEvent $message): void
    {
        $this->entityManager->wrapInTransaction(function () use ($message): void {
            $event = $this->events->findOneByEventIdForUpdate($message->eventId);

            if (null === $event) {
                throw new UnrecoverableMessageHandlingException(sprintf('Event "%s" is not stored, retrying will not help.', $message->eventId));
            }

            if ($event->isPosted()) {
                return; // redelivery: the pair is already in the ledger
            }

            $at = $this->clock->now();

            foreach ($this->transactions->createPair($event, $at) as $transaction) {
                $this->entityManager->persist($transaction);
            }

            $event->markPosted($at);
            $this->entityManager->flush();
        });
    }
}
