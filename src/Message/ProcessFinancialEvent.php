<?php

declare(strict_types=1);

namespace App\Message;

/** Tells the worker to post the ledger pair for an already-stored event. */
final readonly class ProcessFinancialEvent
{
    public function __construct(public string $eventId)
    {
    }
}
