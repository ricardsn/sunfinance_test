<?php

declare(strict_types=1);

namespace App\Message;

/**
 * A message whose handler always throws, so the retry and dead-letter wiring can be
 * watched on a real queue. Dispatched only by app:demo-retry; it touches no data.
 */
final class FailingProbe
{
    public function __construct(public readonly string $id)
    {
    }
}
