<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProcessedEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ProcessedEvent> */
final class ProcessedEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProcessedEvent::class);
    }

    /**
     * Takes a row lock, so if the same message is delivered twice at once the second
     * worker waits and then sees the event already posted. Must run in a transaction.
     */
    public function findOneByEventIdForUpdate(string $eventId): ?ProcessedEvent
    {
        return $this->createQueryBuilder('e')
            ->where('e.eventId = :eventId')
            ->setParameter('eventId', $eventId)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }
}
