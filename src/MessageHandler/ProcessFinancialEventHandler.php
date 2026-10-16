<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\DataManager\AccountBalanceCache;
use App\Enum\EventType;
use App\Factory\AccountingTransactionFactory;
use App\Message\ProcessFinancialEvent;
use App\Repository\ProcessedEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/** Writes the ledger pair for a stored event. Safe to run more than once. */
final readonly class ProcessFinancialEventHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ProcessedEventRepository $events,
        private AccountingTransactionFactory $transactions,
        private AccountBalanceCache $balances,
        private ClockInterface $clock,
    ) {
    }

    #[AsMessageHandler]
    public function post(ProcessFinancialEvent $message): void
    {
        $posted = $this->entityManager->wrapInTransaction(fn (): ?EventType => $this->postInTransaction($message));

        // after the commit, never inside it: a reader that repopulates the key in
        // between would otherwise cache the balance from before the pair was visible
        if (null !== $posted) {
            $this->balances->invalidate(...$posted->accounts());
        }
    }

    /** @return ?EventType the posted event's type, or null if there was nothing to post */
    private function postInTransaction(ProcessFinancialEvent $message): ?EventType
    {
        $event = $this->events->findOneByEventIdForUpdate($message->eventId);

        if (null === $event) {
            throw new UnrecoverableMessageHandlingException(sprintf('Event "%s" is not stored, retrying will not help.', $message->eventId));
        }

        if ($event->isPosted()) {
            return null; // redelivery: the pair is already in the ledger
        }

        $at = $this->clock->now();

        foreach ($this->transactions->createPair($event, $at) as $transaction) {
            $this->entityManager->persist($transaction);
        }

        $event->markPosted($at);
        $this->entityManager->flush();

        return $event->getType();
    }
}
