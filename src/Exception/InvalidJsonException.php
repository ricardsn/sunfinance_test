<?php

declare(strict_types=1);

namespace App\Exception;

/** The request body could not be read as a JSON object. Mapped to 400. */
final class InvalidJsonException extends \RuntimeException
{
}
