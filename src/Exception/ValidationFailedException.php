<?php

declare(strict_types=1);

namespace App\Exception;

/** One or more fields are missing or invalid. Mapped to 422. */
final class ValidationFailedException extends \RuntimeException
{
    /** @param array<string, list<string>> $errors field name => messages */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('The request payload is invalid.');
    }

    /** @return array<string, list<string>> */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
