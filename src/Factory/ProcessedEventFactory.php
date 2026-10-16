<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\ProcessedEvent;
use App\Model\RecordEventRequest;

/** Turns a validated request into the event row, converting the amount to minor units. */
final readonly class ProcessedEventFactory
{
    public function create(RecordEventRequest $request): ProcessedEvent
    {
        return new ProcessedEvent(
            $request->eventId(),
            $request->type(),
            $request->amountInMinorUnits(),
            $request->currency(),
            $request->occurredAt(),
        );
    }
}
