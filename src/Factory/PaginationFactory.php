<?php

declare(strict_types=1);

namespace App\Factory;

use App\Exception\ValidationFailedException;
use App\Model\Pagination;

/**
 * Reads limit and offset off the query string. Both are optional; anything that is not
 * a whole number, or a limit outside 1..MAX_LIMIT, is reported as a validation error
 * rather than quietly clamped - a caller asking for 5000 rows should hear about the cap.
 */
final readonly class PaginationFactory
{
    public function create(mixed $limit, mixed $offset): Pagination
    {
        $errors = [];

        $parsedLimit = $this->toInt($limit, Pagination::DEFAULT_LIMIT);
        $parsedOffset = $this->toInt($offset, 0);

        if (null === $parsedLimit) {
            $errors['limit'] = ['This value should be a positive whole number.'];
        } elseif ($parsedLimit < 1 || $parsedLimit > Pagination::MAX_LIMIT) {
            $errors['limit'] = [sprintf('This value should be between 1 and %d.', Pagination::MAX_LIMIT)];
        }

        if (null === $parsedOffset) {
            $errors['offset'] = ['This value should be zero or a positive whole number.'];
        }

        if ([] !== $errors) {
            throw new ValidationFailedException($errors);
        }

        return new Pagination($parsedLimit, $parsedOffset);
    }

    /** The default if the parameter is absent, null if it is there but is not a whole number. */
    private function toInt(mixed $value, int $default): ?int
    {
        if (null === $value || '' === $value) {
            return $default;
        }

        return is_string($value) && 1 === preg_match('/^\d+$/', $value) ? (int) $value : null;
    }
}
