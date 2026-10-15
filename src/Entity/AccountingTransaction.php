<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AccountName;
use App\Enum\Direction;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The ledger. Insert only - two rows per event, a debit and a credit.
 * No event_id foreign key: processed_events is a dedup log that may be pruned,
 * while these rows are kept for good.
 */
#[ORM\Entity]
#[ORM\Table(name: 'transactions')]
#[ORM\Index(name: 'idx_transactions_event_id', columns: ['event_id'])]
#[ORM\Index(name: 'idx_transactions_account_currency_id', columns: ['account', 'currency', 'id'])]
class AccountingTransaction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(length: 128)]
    private readonly string $eventId;

    #[ORM\Column(length: 32, enumType: AccountName::class)]
    private readonly AccountName $account;

    /** Minor units (cents), always positive - the direction carries the sign. */
    #[ORM\Column(type: Types::BIGINT)]
    private readonly int $amount;

    #[ORM\Column(length: 3)]
    private readonly string $currency;

    #[ORM\Column(length: 6, enumType: Direction::class)]
    private readonly Direction $direction;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private readonly \DateTimeImmutable $createdAt;

    public function __construct(
        string $eventId,
        AccountName $account,
        int $amount,
        string $currency,
        Direction $direction,
        \DateTimeImmutable $createdAt,
    ) {
        $this->eventId = $eventId;
        $this->account = $account;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->direction = $direction;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getAccount(): AccountName
    {
        return $this->account;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getDirection(): Direction
    {
        return $this->direction;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
