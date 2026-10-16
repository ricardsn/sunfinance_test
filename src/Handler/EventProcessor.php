<?php

declare(strict_types=1);

namespace App\Handler;

use App\Exception\DuplicateEventException;
use App\Factory\ProcessedEventFactory;
use App\Message\ProcessFinancialEvent;
use App\Model\RecordEventRequest;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Stores an accepted event and queues the posting job. Both happen in one transaction:
 * the queue table lives in the same database, so the event and its job commit together
 * and neither can exist without the other.
 */
final readonly class EventProcessor
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
        private ProcessedEventFactory $events,
    ) {
    }

    /** @throws DuplicateEventException if this event_id was already accepted */
    public function process(RecordEventRequest $request): void
    {
        $event = $this->events->create($request);

        try {
            $this->entityManager->wrapInTransaction(function () use ($event): void {
                $this->entityManager->persist($event);
                $this->entityManager->flush();
                $this->bus->dispatch(new ProcessFinancialEvent($event->getEventId()));
            });
        } catch (UniqueConstraintViolationException $e) {
            // the UNIQUE index on event_id is the only duplicate check, so two
            // simultaneous requests cannot both get through
            throw new DuplicateEventException($event->getEventId(), $e);
        }
    }
}
