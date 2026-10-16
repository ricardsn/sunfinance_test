<?php

declare(strict_types=1);

namespace App\Exception;

/** This event_id was already accepted. Mapped to 409. */
final class DuplicateEventException extends \RuntimeException
{
    public function __construct(public readonly string $eventId, ?\Throwable $previous = null)
    {
        parent::__construct(sprintf('Event "%s" has already been processed.', $eventId), previous: $previous);
    }
}
