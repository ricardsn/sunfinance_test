<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

/** A message whose handler always throws, so the retry and dead-letter path can be tested. */
final class FailingMessage
{
    public function __construct(public readonly string $id)
    {
    }
}
