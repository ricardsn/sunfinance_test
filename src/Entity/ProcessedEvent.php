<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\EventStatus;
use App\Enum\EventType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row per accepted event. The UNIQUE event_id is what makes processing idempotent:
 * a second request with the same id fails on the constraint instead of being queued again.
 */
#[ORM\Entity]
#[ORM\Table(name: 'processed_events')]
#[ORM\UniqueConstraint(name: 'uniq_processed_events_event_id', columns: ['event_id'])]
class ProcessedEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(length: 128)]
    private readonly string $eventId;

    #[ORM\Column(length: 32, enumType: EventType::class)]
    private readonly EventType $type;

    /** Minor units (cents). */
    #[ORM\Column(type: Types::BIGINT)]
    private readonly int $amount;

    #[ORM\Column(length: 3)]
    private readonly string $currency;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private readonly \DateTimeImmutable $occurredAt;

    #[ORM\Column(length: 16, enumType: EventStatus::class)]
    private EventStatus $status = EventStatus::Pending;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    public function __construct(
        string $eventId,
        EventType $type,
        int $amount,
        string $currency,
        \DateTimeImmutable $occurredAt,
    ) {
        $this->eventId = $eventId;
        $this->type = $type;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->occurredAt = $occurredAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getType(): EventType
    {
        return $this->type;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getStatus(): EventStatus
    {
        return $this->status;
    }

    public function getProcessedAt(): ?\DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function isPosted(): bool
    {
        return EventStatus::Posted === $this->status;
    }

    public function markPosted(\DateTimeImmutable $at): void
    {
        $this->status = EventStatus::Posted;
        $this->processedAt = $at;
    }
}
