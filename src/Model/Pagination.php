<?php

declare(strict_types=1);

namespace App\Model;

/** A checked limit / offset pair. */
final readonly class Pagination
{
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 200;

    public function __construct(
        public int $limit,
        public int $offset,
    ) {
    }
}
