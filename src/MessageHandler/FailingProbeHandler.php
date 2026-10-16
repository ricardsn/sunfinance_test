<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\FailingProbe;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Always fails, on purpose. See App\Message\FailingProbe. */
#[AsMessageHandler]
final class FailingProbeHandler
{
    public function __invoke(FailingProbe $probe): void
    {
        throw new \RuntimeException(sprintf('Probe "%s" fails on purpose.', $probe->id));
    }
}
