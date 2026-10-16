<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Always fails, and counts how often it was asked to. The test reads the counter off
 * this very instance, so it does not need static state: the worker runs in the same
 * kernel as the test and services are shared.
 */
#[AsMessageHandler]
final class FailingMessageHandler
{
    public int $calls = 0;

    public function __invoke(FailingMessage $message): void
    {
        $this->calls++;

        throw new \RuntimeException(sprintf('Handling "%s" always fails.', $message->id));
    }
}
